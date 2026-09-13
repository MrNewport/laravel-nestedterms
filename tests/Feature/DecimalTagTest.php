<?php

use Illuminate\Support\Facades\DB;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;

it('round trips every exact decimal scale without binary floats', function ($scale) {
    $term = Term::create(['name' => 'Exact values']);
    $expected = '12345678901234567890'.($scale === 0 ? '' : '.'.str_repeat('1', $scale));
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:'.$scale, 'value' => $expected]);
    expect($tag->fresh()->type)->toBe('decimal:'.$scale)
        ->and($tag->fresh()->value)->toBe($expected)
        ->and(DB::table('tags')->where('id', $tag->id)->value('value'))->toBe($expected)
        ->and($tag->fresh()->toArray()['value'])->toBe($expected)
        ->and(json_decode($tag->fresh()->toJson(), true)['value'])->toBe($expected);
    $tag->update(['name' => 'Renamed']);
    expect($tag->fresh()->value)->toBe($expected);
})->with(range(0, 6));

it('preserves null and canonicalizes zero on creation and value-only updates', function () {
    $term = Term::create(['name' => 'Nullable']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:2', 'value' => null]);
    expect($tag->fresh()->value)->toBeNull()->and(DB::table('tags')->value('value'))->toBeNull();
    $tag->update(['value' => '-0.000']);
    expect($tag->fresh()->value)->toBe('0.00')->and(DB::table('tags')->value('value'))->toBe('0.00');
    $tag->update(['value' => '81.5']);
    expect($tag->fresh()->value)->toBe('81.50');
    $tag->update(['value' => null]);
    expect($tag->fresh()->value)->toBeNull()->and(DB::table('tags')->value('value'))->toBeNull();
});

it('rejects floating commands even when native dirty comparison would treat them as unchanged', function ($scale, $input) {
    $term = Term::create(['name' => 'Strict input']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:'.$scale, 'value' => '1']);
    $before = DB::table('tags')->where('id', $tag->id)->first();
    expect(fn () => $tag->update(['value' => $input]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('tags')->where('id', $tag->id)->first())->toEqual($before);
    expect(fn () => Tag::create(['term_id' => $term->id, 'name' => 'Bad', 'value' => $input, 'type' => 'decimal:'.$scale]))->toThrow(InvalidArgumentException::class);
    expect(Tag::count())->toBe(1);
})->with([[0, 1.0], [2, 1.25], [2, false]]);

it('rejects malformed and disabled decimal types instead of downgrading their meaning', function ($type) {
    $term = Term::create(['name' => 'Strict type']);
    expect(fn () => Tag::create(['term_id' => $term->id, 'name' => 'Bad', 'type' => $type, 'value' => null]))->toThrow(InvalidArgumentException::class);
    expect(Tag::count())->toBe(0);
})->with(['decimal', 'decimal:', 'decimal:-1', 'decimal:7', 'decimal:02', 'decimal:1.0', 'decimal:2x', 'decimal:2 ', ' decimal:2', 'DECIMAL:2']);

it('requires an allowed decimal target even through a configured synonym', function () {
    config(['nestedterms.allowed_cast_types' => ['integer', 'string'], 'nestedterms.custom_type_map.measurement' => 'decimal:2']);
    $term = Term::create(['name' => 'Allowlist']);
    foreach (['decimal:2', 'measurement'] as $type) {
        expect(fn () => Tag::create(['term_id' => $term->id, 'name' => 'Bad', 'type' => $type, 'value' => '1.25']))->toThrow(InvalidArgumentException::class);
    }
});

it('supports allowed synonyms while retaining the explicit decimal storage type', function () {
    config(['nestedterms.custom_type_map.measurement' => 'decimal:2']);
    $term = Term::create(['name' => 'Synonym']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'measurement', 'value' => '1.2']);
    expect($tag->fresh()->type)->toBe('decimal:2')->and($tag->fresh()->value)->toBe('1.20');
});

it('rescales only exact values and uses the final type regardless of fill ordering', function () {
    $term = Term::create(['name' => 'Type changes']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'integer', 'value' => 12]);
    $tag->update(['type' => 'decimal:2']);
    expect($tag->fresh()->value)->toBe('12.00');
    $tag->update(['type' => 'decimal:1']);
    expect($tag->fresh()->value)->toBe('12.0');
    $tag->update(['value' => '12.25', 'type' => 'decimal:2']);
    expect($tag->fresh()->value)->toBe('12.25');
    expect(fn () => $tag->update(['type' => 'decimal:1']))->toThrow(InvalidArgumentException::class);
    expect($tag->fresh()->type)->toBe('decimal:2')->and($tag->fresh()->value)->toBe('12.25');
    $tag = $tag->fresh();
    $tag->update(['value' => '12.2', 'type' => 'decimal:1']);
    expect($tag->fresh()->value)->toBe('12.2');
    expect(fn () => $tag->update(['type' => 'integer']))->toThrow(InvalidArgumentException::class);
    $tag = $tag->fresh();
    $tag->update(['value' => '12.000', 'type' => 'integer']);
    expect($tag->fresh()->value)->toBe(12);
});

it('preserves the raw stored value when changing a float type into an exact decimal', function () {
    $term = Term::create(['name' => 'Stored representation']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'float', 'value' => 1.25]);
    $tag->update(['type' => 'decimal:2']);
    expect($tag->fresh()->value)->toBe('1.25');
    $tag->update(['type' => 'string']);
    expect($tag->fresh()->value)->toBe('1.25');
    config(['nestedterms.custom_type_map.measurement' => 'decimal:2']);
    $tag->update(['value' => '1.251']);
    $tag->update(['value' => '1.25', 'type' => 'measurement']);
    expect($tag->fresh()->value)->toBe('1.25')->and($tag->fresh()->type)->toBe('decimal:2');
});

it('refuses decimal to integer overflow and preserves null during type changes', function () {
    $term = Term::create(['name' => 'Integer boundary']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:0', 'value' => '9999999999999999999999']);
    expect(fn () => $tag->update(['type' => 'integer']))->toThrow(InvalidArgumentException::class);
    expect($tag->fresh()->value)->toBe('9999999999999999999999');
    $tag = $tag->fresh();
    $tag->update(['value' => null, 'type' => 'integer']);
    expect($tag->fresh()->value)->toBeNull();
    $tag->update(['type' => 'decimal:6']);
    expect($tag->fresh()->value)->toBeNull();
});

it('rejects nonrepresentable raw decimal data on reads and serialization', function () {
    $term = Term::create(['name' => 'Raw data']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:2', 'value' => '1.25']);
    DB::table('tags')->where('id', $tag->id)->update(['value' => '1.251']);
    expect(fn () => $tag->fresh()->value)->toThrow(InvalidArgumentException::class);
    expect(fn () => $tag->fresh()->toArray())->toThrow(InvalidArgumentException::class);
});
