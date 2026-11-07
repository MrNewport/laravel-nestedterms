# Laravel NestedTerms handbook

NestedTerms supplies editable taxonomy buckets (Terms), hierarchical typed Tags and polymorphic membership on host Eloquent models. Use it when users maintain a taxonomy or specification tree; its type casts do not replace domain validation or authorization.

## Fresh Laravel installation

```sh
composer create-project laravel/laravel catalog-app '^12.0'
cd catalog-app
composer require mrnewport/laravel-nestedterms:^2.0
php artisan vendor:publish --tag=nestedterms-config
php artisan migrate
```

Laravel 12 / PHP 8.2+ and Laravel 13 with its supported PHP version are supported. Use `'^13.0'` when creating that major. If your registry has not indexed the tag, add `composer config repositories.nestedterms vcs https://github.com/MrNewport/laravel-nestedterms` and repeat the requirement. Laravel discovers NestedTermsServiceProvider, merges configuration and loads migrations; no UI, public routes or JavaScript is required.

Choose configured tables/models before migrations. The defaults are terms, tags and model_tag; the polymorphic model_id column uses Laravel's standard integer morph migration. There is no UUID/ULID key-type configuration. Applications with different model keys need a reviewed compatible migration before using those subjects. Changing table names later does not move saved data; use an explicit rename/copy migration rather than rerunning initial migrations or dropping production tables.

## Create and use a taxonomy

```php
use MrNewport\LaravelNestedTerms\Models\{Term, Tag};

$term = Term::create(['name' => 'Equipment specifications', 'slug' => 'equipment']);
$dimensions = Tag::create(['term_id' => $term->id, 'name' => 'Dimensions']);
$length = Tag::create([
    'term_id' => $term->id, 'parent_id' => $dimensions->id,
    'name' => 'Length millimeters', 'type' => 'integer', 'value' => 2400,
]);
// $length->slug === 'equipment.dimensions.length-millimeters'
// $length->fresh()->value === 2400
```

Use HasTags on the host model:

```php
class Equipment extends \Illuminate\Database\Eloquent\Model
{
    use \MrNewport\LaravelNestedTerms\Traits\HasTags;
}

$equipment->attachTags($length); // existing saved tag
$equipment->hasTag($length->id); // true
$equipment->tagsByTerm('equipment')->get();
```

This is global Tag membership: the Tag value is stored on the shared Tag record, not separately per equipment/tag pivot. Editing its value changes what every member sees. If each equipment needs an independent measurement, define the domain representation deliberately rather than assuming the pivot stores a per-model value.

## Terms

Term fillable columns: name, slug, description, meta, is_active. meta casts to array, is_active to bool. Terms use timestamps and SoftDeletes. `tags(): HasMany` uses the configured tag_model; `Term::active()` applies `is_active = true` and the usual soft-delete scope.

`generateSlug(): void` assigns Str::slug(name) only when slug is empty. It is called during creation and name updates, but an existing nonempty slug remains stable after a name change. Terms have database-unique names/slugs. Choose explicit slugs when they are durable external references. Soft deletion does not free database unique values; restoring or reusing a slug needs a retention policy.

## Tags, hierarchy and slugs

Tag fillable columns: term_id, parent_id, slug, type, value, name, description, meta, is_active. meta/is_active cast as above; value casts dynamically by type. Tags use timestamps and SoftDeletes. Relations:

- `term(): BelongsTo` uses configured term_model.
- `parent(): BelongsTo` and `children(): HasMany` use the concrete Tag class.
- `models(): MorphToMany` is the generic inverse declaration. For a real typed inverse define a concrete `morphedByMany(Equipment::class, 'model', configuredPivot, 'tag_id', 'model_id')`; one generic relation cannot materialize arbitrary heterogeneous model classes.
- `Tag::active()` filters is_active; this is not an inherited ancestor-active rule.

`generateHierarchySlug(): string` walks the current parent chain, prepends the Term slug, slugifies each tag name and joins with dots. Missing parents produce ModelNotFoundException. A repeated ancestor/self-cycle or parent from another term raises InvalidArgumentException. New tag creation always generates its slug; an explicitly supplied slug is not a way to bypass that hierarchy.

On creation, and when term_id/parent_id/name/type changes, the model validates/falls back the type, regenerates the current tag slug and adds `.2`, `.3`, etc. to avoid a same-term active-row collision. The database unique `(term_id, slug)` constraint remains the final authority; concurrent writes or soft-deleted collisions can still fail. Wrap host editing actions in transactions and use appropriate locks/retry review.

Changing a parent name/term or reparenting a subtree does **not** automatically regenerate descendant slugs. If descendant slugs are public references, plan a reviewed subtree update/redirect strategy under a consistent lock. The package does not fence two concurrent structural edits into a serial tree invariant by itself. Prevent raw bulk updates that bypass the model checks.

`allDescendants(): Eloquent Collection` recursively visits children, excluding the receiver. It uses relationship loading, has no pagination/depth/row budget and should be used on bounded trees. Eager-load an application-known depth or design a bounded query/projection for large taxonomies. The write-time cycle guard is not a repair engine for corrupt historical trees.

## Typed values

| Configured type | Runtime value and limits |
| --- | --- |
| integer | PHP integer cast, not an exact unlimited integer/validation rule. |
| float / double | PHP floating point; unsuitable for exact monetary/decimal evidence. |
| boolean | Eloquent boolean representation; validate source strings before assignment. |
| string | String, also the fallback for absent/unsupported type. |
| array / json | Structured JSON represented through Eloquent's JSON/array casting; pass native arrays when editing. |
| object | Structured JSON hydrated as object via Eloquent. |

The model stores a text representation, while `$tag->fresh()->value` exposes the cast. Structured values avoid a second JSON encoding during creation/name changes. allowed_cast_types defaults to the types above; custom_type_map defaults `number => integer`. A custom synonym is resolved before storage; an unknown input type falls back to string, rather than throwing. Changing type may coerce the current value. Validate types, finite numeric bounds, units, required keys and permissions in the host **before** persistence. This API is not an exact-decimal or arbitrary-schema validator.

Inspect raw stored data via Eloquent raw attributes only when doing a reviewed migration. Test real saved arrays, booleans, nulls and type changes; do not rewrite historical structured values merely to match a new form boundary. The model may use coercive Eloquent casting, so domain validation should not infer valid input from a successful save.

## Membership APIs and queries

`tags(): MorphToMany` uses configured tag_model/model_tag_table and pivot timestamps. Methods accept a Tag instance, integer ID, full slug string, or an array of them:

```php
$article->attachTags([$tagA, $tagB->id]); // syncWithoutDetaching
$article->detachTags($tagA);            // remove selected memberships
$article->syncTags([$tagB->slug]);       // replace all memberships
$article->syncTags([$tagA], false);      // retain memberships not listed
$article->hasTag($tagB);                // bool
$article->tagsByTerm($term->id)->get();  // int means ID
$article->tagsByTerm('equipment')->get(); // string means slug
```

Strings are always slugs, including numeric-looking strings. Unknown slugs/unsupported values are ignored by normalization; integer IDs are accepted without proving an authorized Tag exists. Therefore validate and scope requested tags explicitly before `syncTags()`: a list of unknown slugs can normalize to empty and detach everything. Use full hierarchical slugs (not bare leaf names). Slug equality follows the database collation; use IDs for unambiguous reviewed membership.

`tagsByTerm()` returns a relation query, so add active filters/order/pagination as required. `syncTags()` affects the complete membership list, not only a specific term. To edit one term, retain IDs of other authorized terms and combine them deliberately in the host transaction. Do not treat is_active or term membership as tenant scoping; package tables have no owner column.

## Configuration and extension

Publish nestedterms-config before installation changes. Configuration keys: terms_table, tags_table, model_tag_table, term_model, tag_model, allowed_cast_types, custom_type_map. Custom classes should extend the base models so HasTags recognizes Tag subclasses. Override generateHierarchySlug when a tested domain convention is needed; keep cycle/same-term checks by calling parent.

Configure both model classes where appropriate. Term→tags and Tag→term use config, self-relations use the concrete Tag subclass, and HasTags uses configured table/model names. Additional custom columns need host migrations/fillable/casts. Existing data is not renamed or converted by config changes. Standard Eloquent events apply; the package defines no external durable event bus or automatic workflow.

## Data-preserving upgrades and recipes

On a copy of existing data verify term/tag IDs, slugs, parent chains, types/raw structured values, soft-deleted collisions, pivot membership and both relationship directions. Back up before forward migrations. An embedded older implementation is a distinct data/API contract and requires an explicit adapter/mapping, not a namespace search-and-replace. Preserve domain ownership and concurrent edits in the host.

[RECIPES.md](RECIPES.md) covers an editable specification catalog, a term-scoped membership editor and typed JSON feature flags. [FEATURES.md](FEATURES.md) covers every public method. `composer test` runs the isolated suite; MySQL migration tests require their disposable configured database. Existing tests provide executable examples of cycles, cast round trips and custom tables/models.

## Optional composition

Fields can validate an administrator's proposed name/type/value before saving Tags; it does not change the shared-value model. Flow/Workflow can review taxonomy proposals before publishing them; the application retains versioning and policy. Stow can store catalog products that happen to have tags, but membership in a basket and membership in a taxonomy are separate contracts. No other package is required.
