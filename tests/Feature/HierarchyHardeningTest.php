<?php

use Illuminate\Support\Facades\DB;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;

class CustomSlugTag extends Tag
{
    public function generateHierarchySlug(): string
    {
        return 'custom';
    }
}

it('supports the full configured depth with short stable identifiers', function () {
    config(['nestedterms.slug_mode' => 'stable']);
    $term = Term::create(['name' => 'Deep']);
    $root = $parent = Tag::create(['term_id' => $term->id, 'name' => 'Root']);
    for ($depth = 1; $depth <= 256; $depth++) {
        $parent = Tag::create(['term_id' => $term->id, 'parent_id' => $parent->id, 'name' => 'A long descriptive node name']);
        expect(strlen($parent->slug))->toBe(32);
    }
    expect($parent->ancestors())->toHaveCount(256)
        ->and($root->allDescendants())->toHaveCount(256);
    expect(fn () => Tag::create(['term_id' => $term->id, 'parent_id' => $parent->id, 'name' => 'Too deep']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $parent->ancestors(255))->toThrow(InvalidArgumentException::class);
    expect(fn () => $root->allDescendants(255))->toThrow(InvalidArgumentException::class);
    expect(Tag::count())->toBe(257);
});

it('preserves explicit stable identity across rename and reparent and rejects edits', function () {
    config(['nestedterms.slug_mode' => 'stable']);
    $term = Term::create(['name' => 'Stable']);
    $parent = Tag::create(['term_id' => $term->id, 'name' => 'Parent']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Child', 'slug' => '0']);
    $tag->update(['name' => 'Renamed', 'parent_id' => $parent->id]);
    expect($tag->fresh()->slug)->toBe('0')->and($tag->ancestors()->modelKeys())->toBe([$parent->id]);
    expect(fn () => $tag->update(['slug' => 'different']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $tag->update(['slug' => 'different', 'name' => 'Also renamed']))->toThrow(InvalidArgumentException::class);
    expect($tag->fresh()->slug)->toBe('0')->and($tag->fresh()->name)->toBe('Renamed');
});

it('enforces length including collision suffixes and deleted reservations', function ($mode) {
    config(['nestedterms.slug_mode' => $mode, 'nestedterms.max_slug_length' => 5]);
    $term = Term::create(['name' => 'S']);
    $first = Tag::create(['term_id' => $term->id, 'name' => 'Abc', 'slug' => 's.abc']);
    $first->delete();
    expect(fn () => Tag::create(['term_id' => $term->id, 'name' => 'Abc', 'slug' => 's.abc']))->toThrow(LengthException::class);
    expect(fn () => Tag::create(['term_id' => $term->id, 'name' => 'Too long', 'slug' => 'too-long']))->toThrow(LengthException::class);
    expect(Tag::withTrashed()->count())->toBe(1);
    config(['nestedterms.max_slug_length' => 7]);
    expect(Tag::create(['term_id' => $term->id, 'name' => 'Abc', 'slug' => 's.abc'])->slug)->toBe('s.abc.2');
})->with(['hierarchical', 'stable']);

it('validates hierarchy even with a custom slug renderer', function ($mode) {
    config(['nestedterms.slug_mode' => $mode, 'nestedterms.limits.max_depth' => 2]);
    $term = Term::create(['name' => 'Tree']);
    $other = Term::create(['name' => 'Other']);
    $root = CustomSlugTag::create(['term_id' => $term->id, 'name' => 'Root']);
    $child = CustomSlugTag::create(['term_id' => $term->id, 'parent_id' => $root->id, 'name' => 'Child']);
    $leaf = CustomSlugTag::create(['term_id' => $term->id, 'parent_id' => $child->id, 'name' => 'Leaf']);
    expect(fn () => $root->update(['parent_id' => $leaf->id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => CustomSlugTag::create(['term_id' => $other->id, 'parent_id' => $child->id, 'name' => 'Foreign']))->toThrow(InvalidArgumentException::class);
    expect(fn () => CustomSlugTag::create(['term_id' => $term->id, 'parent_id' => $leaf->id, 'name' => 'Too deep']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $child->update(['term_id' => $other->id, 'parent_id' => null]))->toThrow(InvalidArgumentException::class);
})->with(['hierarchical', 'stable']);

it('rejects a reparent that would put descendants beyond the depth limit', function () {
    config(['nestedterms.slug_mode' => 'stable', 'nestedterms.limits.max_depth' => 2]);
    $term = Term::create(['name' => 'Tree']);
    $root = Tag::create(['term_id' => $term->id, 'name' => 'Root']);
    $child = Tag::create(['term_id' => $term->id, 'parent_id' => $root->id, 'name' => 'Child']);
    Tag::create(['term_id' => $term->id, 'parent_id' => $child->id, 'name' => 'Leaf']);
    $destination = Tag::create(['term_id' => $term->id, 'name' => 'Destination']);
    expect(fn () => $root->update(['parent_id' => $destination->id]))->toThrow(InvalidArgumentException::class);
    expect($root->fresh()->parent_id)->toBeNull();
});

it('bounds wide traversal and detects corrupt cycles without using loaded children', function () {
    config(['nestedterms.slug_mode' => 'stable']);
    $term = Term::create(['name' => 'Tree']);
    $root = Tag::create(['term_id' => $term->id, 'name' => 'Root']);
    $root->load('children');
    $children = collect(range(1, 4))->map(fn ($number) => Tag::create(['term_id' => $term->id, 'parent_id' => $root->id, 'name' => 'Child '.$number]));
    expect($root->allDescendants()->modelKeys())->toBe($children->pluck('id')->all());
    expect(fn () => $root->allDescendants(maxNodes: 3))->toThrow(InvalidArgumentException::class);
    config(['nestedterms.limits.max_descendants' => 3]);
    expect(fn () => $root->allDescendants(maxNodes: 100))->toThrow(InvalidArgumentException::class);
    config(['nestedterms.limits.max_descendants' => 5000]);
    DB::table('tags')->where('id', $root->id)->update(['parent_id' => $children->first()->id]);
    expect(fn () => $root->fresh()->allDescendants())->toThrow(InvalidArgumentException::class);
    expect(fn () => $children->first()->ancestors())->toThrow(InvalidArgumentException::class);
});

it('leaves existing hierarchical descendant slugs unchanged after ancestor edits', function () {
    $term = Term::create(['name' => 'Tree']);
    $root = Tag::create(['term_id' => $term->id, 'name' => 'Root']);
    $child = Tag::create(['term_id' => $term->id, 'parent_id' => $root->id, 'name' => 'Child']);
    $root->update(['name' => 'Renamed']);
    expect($root->fresh()->slug)->toBe('tree.renamed')
        ->and($child->fresh()->slug)->toBe('tree.root.child')
        ->and($child->ancestors()->first()->name)->toBe('Renamed');
});

it('preserves typed null and false or zero on creation and value-only updates', function ($type, $value) {
    $term = Term::create(['name' => 'Values']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Value', 'type' => $type, 'value' => null]);
    expect($tag->fresh()->value)->toBeNull()->and(DB::table('tags')->value('value'))->toBeNull();
    $tag->update(['value' => $value]);
    expect($tag->fresh()->value)->toEqual($value);
    $tag = $tag->fresh();
    $tag->update(['value' => null]);
    $tag->update(['name' => 'Renamed']);
    expect($tag->fresh()->value)->toBeNull()->and(DB::table('tags')->value('value'))->toBeNull();
})->with([
    ['integer', 0], ['boolean', false], ['float', 0.0], ['double', 0.0],
    ['string', ''], ['array', []], ['json', []], ['object', (object) ['count' => 0]],
]);
