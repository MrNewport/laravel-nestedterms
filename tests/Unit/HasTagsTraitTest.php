<?php

use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;
use MrNewport\LaravelNestedTerms\Tests\Models\TestModel;

/**
 * Tests the HasTags trait on a generic "ItemTestModel"
 * that references the ephemeral "test_items" table.
 */
beforeEach(function () {
    // 1) Drop & create the ephemeral table
    Schema::dropIfExists('test_models');
    Schema::create('test_models', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
});

it('can attach and detach tags to a generic item', function () {
    $term = Term::create(['name' => 'GeneralTerm', 'slug' => 'general']);
    $tag  = Tag::create(['term_id' => $term->id, 'name' => 'AttachTest']);

    $item = TestModel::create(['name' => 'Demo Item']);

    // Attach
    $item->attachTags($tag);
    expect($item->tags)->toHaveCount(1);

    // Detach
    $item->detachTags($tag);
    expect($item->tags()->count())->toBe(0);
});

it('can filter tags by term slug', function () {
    $term1 = Term::create(['name' => 'TermOne', 'slug' => 'termone']);
    $term2 = Term::create(['name' => 'TermTwo', 'slug' => 'termtwo']);

    $tag1 = Tag::create(['term_id' => $term1->id, 'name' => 'Apple']);
    $tag2 = Tag::create(['term_id' => $term2->id, 'name' => 'Banana']);

    $item = TestModel::create(['name' => 'TaggedItem']);
    $item->attachTags([$tag1, $tag2]);

    $termOneTags = $item->tagsByTerm('termone')->get();
    expect($termOneTags)->toHaveCount(1)
        ->and($termOneTags->first()->name)->toBe('Apple');
});
