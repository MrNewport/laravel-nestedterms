# Laravel Nested Terms & Tags

A **dynamic & extensible** system for **nested** Terms and **dynamic-cast** Tags in Laravel. This package includes:

- **Infinite nesting** of Tags via a self-referencing `parent_id`.  
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
  - [Infinite Nesting](#infinite-nesting)
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

- **PHP** `^8.1` or higher  
- **Laravel** `^11.0` (or equivalent)  
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

### Infinite Nesting

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
```

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

Then re-run `php artisan migrate`. The included migrations reference these config values.

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
- **TagTest**: infinite nesting, dynamic casting, hierarchical slugs.
- **HasTagsTraitTest**: attaching/detaching tags on a test model.

---

## Contributing

1. **Fork** this repo.
2. **Create** a feature/fix branch.
3. **Add** tests covering changes.
4. **Submit** a Pull Request.

---

## License

Licensed under the [MIT license](LICENSE).

Enjoy building infinite nesting, dynamic-cast tags, and configurable terms in your Laravel projects!
