<?php

use Illuminate\Support\Str;
use MrNewport\LaravelNestedTerms\Models\Term;

/**
 * Tests creation and usage of Terms.
 * Assumes "terms" table is provided by the package migrations.
 */

it('can create a term with name and slug', function () {
    $term = Term::create([
        'name' => 'Specifications',
        'slug' => 'specifications',
    ]);

    expect($term->id)->toBeInt()
        ->and($term->slug)->toBe('specifications');
});

it('auto-generates a slug if missing', function () {
    $term = Term::create([
        'name' => 'Lease Attributes',
    ]);

    expect(Str::slug($term->name))->toBe($term->slug);
});

it('marks term active by default', function () {
    $term = Term::create(['name' => 'Public Term']);
    expect($term->refresh()->is_active)->toBeTrue();
});
