# NestedTerms recipes

## Editable equipment specification catalog

Create one Term with an explicit durable slug. Under it create a hierarchy such as Dimensions → Length and Electrical → Voltage. Each saved Tag holds its shared type/value. Authorize catalog editing, validate numeric units/ranges in the host, then create/reparent through model saves inside a transaction. Prefer immutable or explicitly versioned published values when historical equipment evidence must retain its original measurement.

```php
$term = Term::firstOrCreate(['slug' => 'equipment-specifications'], ['name' => 'Equipment specifications']);
$dimensions = Tag::create(['term_id' => $term->id, 'name' => 'Dimensions']);
$length = Tag::create([
    'term_id' => $term->id, 'parent_id' => $dimensions->id,
    'name' => 'Length 2400mm', 'type' => 'integer', 'value' => 2400,
]);
$equipment->attachTags($length);
```

Use full slug/ID for public filters and `whereHas('tags', ...)` on authorized equipment queries. Eager-load needed tags/terms in read projections. A tag is shared catalog data: editing it affects every attached model, so avoid storing individual equipment facts there unless that sharing is intended. Parent rename does not rewrite descendant slugs; prepare redirects/reindexing in the domain action.

## Edit one term without removing another

The generic syncTags() replaces every membership. A form for amenities must preserve specification memberships:

```php
DB::transaction(function () use ($equipment, $term, $requestedIds) {
    $current = $equipment->newQuery()->whereKey($equipment->getKey())->lockForUpdate()->firstOrFail();
    Gate::authorize('updateTaxonomy', $current);
    $allowed = Tag::query()->where('term_id', $term->id)->active()->whereIn('id', $requestedIds)->get();
    if ($allowed->count() !== count(array_unique($requestedIds))) {
        throw new DomainException('One or more tags cannot be selected.');
    }
    $otherIds = $current->tags()->where('term_id', '!=', $term->id)->pluck('tags.id')->all();
    $current->syncTags([...$otherIds, ...$allowed->modelKeys()]);
});
```

This example uses default tags table; qualify with the configured related model's table for custom names. Scope requestedIds to the domain's permitted workspace/catalog before querying. Every cooperating writer uses the same equipment lock. Do not pass unchecked slugs: unknown strings are ignored and can normalize to a destructive empty replacement.

## Structured feature values

A JSON/array tag can represent shared catalog capabilities:

```php
$capability = Tag::create([
    'term_id' => $term->id, 'name' => 'Wireless capabilities', 'type' => 'array',
    'value' => ['bands' => ['2.4GHz', '5GHz'], 'mesh' => false],
]);
$value = $capability->fresh()->value;
```

Pass native arrays; use domain validation for allowed bands, boolean meaning and size before saving. Do not assume casting enforces that schema. Unknown types fall back to string; exact decimal specifications need a separately tested representation. Use the [structured round-trip tests](../tests/Feature/TagTest.php) as an executable baseline for name updates, booleans and arrays.
