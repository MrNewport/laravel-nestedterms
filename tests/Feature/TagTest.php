<?php

use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;

/**
 * Tests creation and usage of Tags, including
 * infinite nesting, slug generation, and dynamic casting.
 */

it('can create a tag under a term', function () {
    $term = Term::create([
        'name' => 'Specifications',
        'slug' => 'specifications',
    ]);

    $tag = Tag::create([
        'term_id' => $term->id,
        'name'    => 'Bedrooms',
    ]);

    expect($tag->id)->toBeInt()
        ->and($tag->term_id)->toBe($term->id)
        ->and($tag->slug)->toBe('specifications.bedrooms');
});

it('allows infinite nesting of tags', function () {
    $term = Term::create(['name' => 'Specs', 'slug' => 'specs']);

    $parent = Tag::create([
        'term_id' => $term->id,
        'name'    => 'SubItem',
    ]);
    $child = Tag::create([
        'term_id'   => $term->id,
        'parent_id' => $parent->id,
        'name'      => 'Child Tag',
    ]);

    // Expected slug: "specs.subitem.child-tag"
    expect($child->slug)->toBe('specs.subitem.child-tag');

    // allDescendants check
    $descendants = $parent->allDescendants();
    expect($descendants)->toHaveCount(1)
        ->and($descendants->first()->name)->toBe('Child Tag');
});

it('casts value based on type', function () {
    $term = Term::create(['name' => 'GenericTerm', 'slug' => 'generic']);

    $tag = Tag::create([
        'term_id' => $term->id,
        'name'    => 'SomeNumber',
        'type'    => 'integer',
        'value'   => '10',
    ]);
    $tag = $tag->fresh();

    // Should be integer at runtime, although stored as string in DB
    expect($tag->value)->toBe(10);
});

