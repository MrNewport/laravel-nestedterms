# Laravel Nestedterms

Current release: **2.0.0**. Supported installations: Laravel 12 (PHP 8.2+) and Laravel 13 (PHP 8.3+). CI verifies supported PHP/Laravel combinations. Earlier Laravel versions should remain on the previous major release.

Laravel 12/13 with bounded component dependencies. Fixed structured values being double-encoded, configurable table membership lookups and custom model relations. Parent traversal now rejects cycles and cross-term parents. Modernized the case-sensitive Pest bootstrap and added regression tests.

```sh
composer require mrnewport/laravel-nestedterms:^2.0
composer test # from the package checkout; tests use isolated fixtures
```

GitHub source and tags are published first. Until the release is indexed on Packagist, add this repository as a Composer VCS repository. Never install test dependencies in your production application's require section.

Run `php artisan migrate` after upgrading to apply package migrations. Back up application data before normal production migrations.

The unreleased changes in this checkout add scoped atomic attachments, migration opt-out, bounded traversal, and an optional stable slug mode. They are not part of the published 2.0.0 release yet.

## Existing API reference

# Laravel Nested Terms & Tags

A **dynamic & extensible** system for **nested** Terms and **dynamic-cast** Tags in Laravel. This package includes:

- **Configurable bounded nesting** of Tags via a self-referencing `parent_id`.
- **Hierarchical slugs** (e.g. `specifications.bedrooms.5`).  
- **Dynamic casting** of a Tag’s `value` based on a `type` field (`integer`, `boolean`, `float`, `array`, etc.).  
- **Polymorphic pivot** for attaching Tags to any Eloquent model.  
- **Config-driven architecture** for easy customization.  
- **Comprehensive tests** and a production-ready codebase.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Creating Terms](#creating-terms)
  - [Creating Tags](#creating-tags)
  - [Nested Tags](#nested-tags)
  - [Tag Values & Casting](#tag-values--casting)
  - [Attaching Tags to Models](#attaching-tags-to-models)
  - [Filtering by Term](#filtering-by-term)
- [Customization](#customization)
  - [Custom Table Names](#custom-table-names)
  - [Custom Models](#custom-models)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Requirements

- **PHP** 8.2+ for Laravel 12; PHP 8.3+ for Laravel 13
- **Laravel** 12 or 13
- **Composer** for dependency management  

---

## Installation

1. **Install** via Composer:

   ```bash
   composer require mrnewport/laravel-nestedterms
   ```

2. **Publish** the config (optional):

   ```bash
   php artisan vendor:publish --provider="MrNewport\LaravelNestedTerms\Providers\NestedTermsServiceProvider" --tag=nestedterms-config
   ```

3. **Migrate**:

   ```bash
   php artisan migrate
   ```

---

## Configuration

**`config/nestedterms.php`** (published if desired):

```php
return [
    'load_migrations' => true,
    'slug_mode' => 'hierarchical', // or 'stable'
    'max_slug_length' => 255,
    'limits' => [
        'max_depth' => 256,
        'max_descendants' => 5000,
        'max_attachment_inputs' => 1000,
    ],

    'terms_table'     => 'terms',
    'tags_table'      => 'tags',
    'model_tag_table' => 'model_tag',

    'term_model' => \MrNewport\LaravelNestedTerms\Models\Term::class,
    'tag_model'  => \MrNewport\LaravelNestedTerms\Models\Tag::class,

    'allowed_cast_types' => [
        'integer','float','double','boolean','string','array','json','object',
    ],

    'custom_type_map' => [
        'number' => 'integer',
    ],
];
```

- **Table Names**: `terms_table`, `tags_table`, `model_tag_table`
- **Model Classes**: `term_model` & `tag_model`
- **Casting**: `'allowed_cast_types'` & `'custom_type_map'`

Set `load_migrations` to `false` in application configuration before service provider boot to use application-owned tables and constraints. This suppresses automatic package migration loading; it does not remove migrations already published to the application's migration directory or change existing tables. The host application must supply all required columns, timestamps, unique keys, and foreign keys.

The default schema is not tenant-aware. Tenant applications must configure scoped subclasses for both Term and Tag, own the tenant constraints on catalogs and attachments, and authorize the model receiving attachments. The helpers resolve tags through the configured model's current global scopes, including soft-delete scope; they do not authorize the receiving model. All participating models must share a database connection. Applications must serialize concurrent hierarchy/catalog changes and attachment mutations when stronger cross-operation guarantees are required. Raw query-builder writes and direct relation mutations bypass these model/helper checks.

---

## Usage

### Creating Terms

```php
use MrNewport\LaravelNestedTerms\Models\Term;

$term = Term::create([
    'name' => 'Specifications',
    'slug' => 'specifications', // or omit to auto-generate
]);
```

### Creating Tags

```php
use MrNewport\LaravelNestedTerms\Models\Tag;

$bedrooms = Tag::create([
    'term_id' => $term->id,
    'name'    => 'Bedrooms',
]);
```

### Nested Tags

Tags can nest via `parent_id`:

```php
$parent = Tag::create([
  'term_id' => $term->id,
  'name'    => 'SubItem',
]);

$child = Tag::create([
  'term_id'   => $term->id,
  'parent_id' => $parent->id,
  'name'      => 'Child Tag',
]);

// slug => "specifications.subitem.child-tag"
$descendants = $parent->allDescendants(); // includes "Child Tag"
$ancestors = $child->ancestors(); // nearest parent first
```

Traversal uses fresh scoped queries, not previously loaded relations. Descendants are returned breadth first; ancestry is returned nearest parent first. The defaults allow 256 ancestors (root depth zero) and 5,000 descendants per traversal. A cycle, cross-term link, unavailable ancestor, or exceeded limit throws instead of returning a partial tree. Optional `ancestors(maxDepth: 32)` and `allDescendants(maxDepth: 32, maxNodes: 500)` may lower the configured limits. Limits must be positive. Reparenting also validates the descendant branch against its new depth.

#### Slug identity and hierarchy display

The default `hierarchical` mode renders the current tag's ancestry when it is created or its name, parent, term, or type changes. Existing descendants are **not** rewritten when an ancestor is renamed or reparented. A stored hierarchical slug is therefore a snapshot, not an authoritative current path. Build current hierarchy displays from the relationships or `ancestors()`.

For deep or frequently reorganized catalogs, opt into stable identifiers:

```php
// config/nestedterms.php
'slug_mode' => 'stable',

// Creation accepts an explicit nonempty identifier, or generates 32 hex characters.
$tag = Tag::create(['term_id' => $term->id, 'name' => 'Bedrooms', 'slug' => 'bedrooms']);
$tag->update(['name' => 'Bedroom count', 'parent_id' => $parent->id]);
// $tag->slug remains 'bedrooms'. Later explicit slug edits throw.
```

Stable mode applies to Tag slugs; Term behavior remains unchanged. Enabling it for existing rows preserves their current slugs as immutable identities, without rewriting them. Switching back to hierarchical mode can regenerate a tag's slug on a later structural edit. Hierarchy validation applies in both modes and also when `generateHierarchySlug()` is overridden.

`max_slug_length` defaults to the package schema's 255-character column. Longer slugs, empty rendered slugs, and collision suffixes that exceed the limit throw `LengthException`; values are never silently truncated. Collisions within a term receive `.2`, `.3`, etc., including reserved soft-deleted slugs. Configure the limit to match an application-owned schema. A limit below 32 requires an explicit shorter stable identifier. Database uniqueness remains authoritative for simultaneous creation.

### Tag Values & Casting

```php
$tag = Tag::create([
    'term_id' => $term->id,
    'name'    => 'NumberOfRooms',
    'type'    => 'integer',
    'value'   => '5',
]);

// Eloquent interprets $tag->value as integer
echo $tag->value;       // 5
```

Typed `null` remains SQL `NULL` on creation and updates. It is distinct from integer zero, boolean false, and an empty string. Existing cast fallback/coercion remains supported; domain ranges and allowed values belong to the application's validation. Floating-point casts do not provide exact decimal arithmetic.

### Attaching Tags to Models

Any model can “have tags”:

```php
use MrNewport\LaravelNestedTerms\Traits\HasTags;

class Article extends Model
{
    use HasTags;
}
```

```php
$article->attachTags($tagIdOrSlug);
$article->detachTags($tagInstance);
$article->syncTags([...]);
$article->hasTag('bedrooms');
```

Attachment helpers accept persisted configured Tag instances, positive integer IDs, or exact nonempty slug strings, including mixed arrays/collections. Numeric strings are slugs, not IDs. Every input must resolve unambiguously through the configured current Tag scope before any pivot write. Missing, foreign, deleted, unsaved, invalid, ambiguous, or over-limit inputs throw `InvalidArgumentException` and leave existing attachments intact. Pivot failures roll back the whole helper operation. `syncTags([])` explicitly clears all attachments; `syncTags(null)` throws. The default input limit is 1,000 items, including duplicates.

For the inverse relation, pass a concrete Eloquent model class:

```php
$articles = $tag->modelsOfType(Article::class)->get();

// A named relation on a custom Tag model can also be eager loaded.
public function articles(): \Illuminate\Database\Eloquent\Relations\MorphToMany
{
    return $this->modelsOfType(Article::class);
}
```

This supports configured pivot table names and Laravel morph aliases. The old argument-free `models()` cannot infer a concrete polymorphic class and throws a descriptive exception.

### Filtering by Term

```php
$article->tagsByTerm('specifications')->get();
// returns tags whose term->slug == "specifications"
```

---

## Customization

### Custom Table Names

In **`nestedterms.php`**:

```php
'terms_table' => 'cms_terms',
'tags_table'  => 'cms_tags',
```

Set table names before the first migration. The included migrations reference these config values; changing configuration later does not rename existing tables. Use an explicit application migration when renaming an existing schema.

### Custom Models

Define your own **Term** or **Tag** classes:

```php
namespace App\Models;

use MrNewport\LaravelNestedTerms\Models\Tag as BaseTag;

class CustomTag extends BaseTag
{
    protected $fillable = [
        'term_id','parent_id','slug','type','value','name','description','meta','is_active',
        'icon','color'
    ];

    public function generateHierarchySlug(): string
    {
        $slug = parent::generateHierarchySlug();
        return $slug.'-extended';
    }
}
```

Then update:

```php
'tag_model' => \App\Models\CustomTag::class,
```

---

## Testing

A **Pest-based** suite covers Terms, Tags, and the `HasTags` trait. Run:

```bash
composer test
```

- **TermTest**: verifying creation, slug generation, etc.
- **TagTest / HierarchyHardeningTest**: bounded deep nesting, dynamic casting, null updates, cycles, slug identity, and length limits.
- **HasTagsTraitTest**: attaching/detaching tags on a test model.
- **ScopedAttachmentTest**: custom tables/models/scopes, atomic failures, and concrete inverse relations.
- **MigrationOptOutTest**: package schema is not registered or applied when disabled.

The MySQL hardening test is opt-in locally:

```sh
MYSQL_HARDENING_DATABASE=nestedterms_hardening_local_test MYSQL_TEST_PORT=3312 \
  vendor/bin/pest tests/Feature/MySqlHardeningTest.php
```

It uses localhost, the root test account, and `MYSQL_TEST_PASSWORD` (empty by default). Its schema name must match `nestedterms_hardening_<1–16 lowercase letters/digits>_test`. It refuses any existing schema, creates a dedicated disposable database, and drops that database in cleanup. Use only a disposable MySQL server. CI runs this case alongside the existing migration smoke test and separately runs the Laravel 12/13 compatibility matrix.

---

## Contributing

1. **Fork** this repo.
2. **Create** a feature/fix branch.
3. **Add** tests covering changes.
4. **Submit** a Pull Request.

---

## License

Licensed under the [MIT license](LICENSE).

Build nested catalogs, typed values, and configurable terms in your Laravel projects.
