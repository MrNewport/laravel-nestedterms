<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTag;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\TestModel;

beforeEach(function () {
    Schema::rename('terms', 'catalog_terms');
    Schema::rename('tags', 'catalog_tags');
    Schema::rename('model_tag', 'catalog_links');
    Schema::table('catalog_terms', fn ($table) => $table->unsignedBigInteger('tenant_id')->default(1));
    Schema::table('catalog_tags', fn ($table) => $table->unsignedBigInteger('tenant_id')->default(1));
    Schema::create('test_models', function ($table) {
        $table->id();
        $table->string('name');
    });
    config([
        'testing.tenant_id' => 1,
        'nestedterms.terms_table' => 'catalog_terms',
        'nestedterms.tags_table' => 'catalog_tags',
        'nestedterms.model_tag_table' => 'catalog_links',
        'nestedterms.term_model' => ScopedTerm::class,
        'nestedterms.tag_model' => ScopedTag::class,
        'nestedterms.slug_mode' => 'stable',
    ]);
    $this->term = ScopedTerm::create(['name' => 'Current term']);
    $this->first = ScopedTag::create(['term_id' => $this->term->id, 'name' => 'First', 'slug' => 'first']);
    $this->second = ScopedTag::create(['term_id' => $this->term->id, 'name' => 'Second', 'slug' => 'second']);
    config(['testing.tenant_id' => 2]);
    $this->foreignTerm = ScopedTerm::create(['name' => 'Foreign term']);
    $this->foreign = ScopedTag::create(['term_id' => $this->foreignTerm->id, 'name' => 'Foreign', 'slug' => 'foreign']);
    config(['testing.tenant_id' => 1]);
    $this->item = TestModel::create(['name' => 'Item']);
    $this->item->attachTags($this->first);
});

it('resolves configured scoped models IDs and slugs together and supports explicit empty sync', function () {
    $this->item->syncTags(collect([$this->first, $this->second->id, 'first', 'second']));
    expect($this->item->tags()->orderBy('catalog_tags.id')->get()->modelKeys())->toBe([$this->first->id, $this->second->id])
        ->and($this->term->tags()->first())->toBeInstanceOf(ScopedTag::class)
        ->and($this->first->term()->first())->toBeInstanceOf(ScopedTerm::class);
    $this->item->detachTags('second');
    expect($this->item->tags()->count())->toBe(1);
    $this->item->syncTags([]);
    expect(DB::table('catalog_links')->count())->toBe(0);
});

it('rejects foreign or unavailable inputs atomically for every mutation', function ($kind, $method) {
    $input = match ($kind) {
        'id' => $this->foreign->id,
        'slug' => $this->foreign->slug,
        'model' => $this->foreign,
        'missing' => 99999,
        'missing-slug' => 'missing',
        'base-model' => Tag::findOrFail($this->first->id),
        'unsaved' => new ScopedTag(['name' => 'Unsaved']),
        'null' => null,
        'boolean' => false,
        'float' => 1.5,
        'array' => ['id' => $this->first->id],
        'empty' => '',
        'zero' => 0,
    };
    expect(fn () => $this->item->{$method}([$this->second, $input]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$this->first->id]);
})->with(['id', 'slug', 'model', 'missing', 'missing-slug', 'base-model', 'unsaved', 'null', 'boolean', 'float', 'array', 'empty', 'zero'])
    ->with(['attachTags', 'detachTags', 'syncTags']);

it('rejects formerly visible and soft deleted model inputs using the current scope', function () {
    $this->second->delete();
    expect(fn () => $this->item->syncTags($this->second))->toThrow(InvalidArgumentException::class);
    config(['testing.tenant_id' => 2]);
    expect(fn () => $this->item->syncTags($this->first))->toThrow(InvalidArgumentException::class);
    expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$this->first->id]);
});

it('rejects ambiguous scoped slugs and bounded input overflow before clearing anything', function () {
    $another = ScopedTerm::create(['name' => 'Another current term']);
    ScopedTag::create(['term_id' => $another->id, 'name' => 'Duplicate', 'slug' => 'second']);
    expect(fn () => $this->item->syncTags(['second']))->toThrow(InvalidArgumentException::class);
    config(['nestedterms.limits.max_attachment_inputs' => 2]);
    expect(fn () => $this->item->syncTags([$this->first, $this->second, $this->first]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$this->first->id]);
});

it('rolls back earlier detach operations when a later pivot insertion fails', function () {
    DB::unprepared('CREATE TRIGGER reject_catalog_links BEFORE INSERT ON catalog_links BEGIN SELECT RAISE(ABORT, \'synthetic pivot failure\'); END');
    expect(fn () => $this->item->syncTags([$this->second]))->toThrow(QueryException::class);
    expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$this->first->id]);
});

it('requires one connection for atomic attachment mutation', function () {
    config(['database.connections.alias' => config('database.connections.testing')]);
    $this->item->setConnection('alias');
    expect(fn () => $this->item->syncTags([$this->second]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$this->first->id]);
});

it('validates scoped terms and parents independently of stable slug rendering', function () {
    expect(fn () => ScopedTag::create(['term_id' => $this->foreignTerm->id, 'name' => 'Foreign term']))->toThrow(ModelNotFoundException::class);
    expect(fn () => ScopedTag::create(['term_id' => $this->term->id, 'parent_id' => $this->foreign->id, 'name' => 'Foreign parent']))->toThrow(ModelNotFoundException::class);
    $child = ScopedTag::create(['term_id' => $this->term->id, 'parent_id' => $this->first->id, 'name' => 'Child']);
    expect($this->first->allDescendants()->modelKeys())->toBe([$child->id])
        ->and($child->ancestors()->modelKeys())->toBe([$this->first->id]);
});

it('offers a concrete inverse relation with custom table names and morph aliases', function () {
    Relation::enforceMorphMap(['test-item' => TestModel::class]);
    try {
        $other = TestModel::create(['name' => 'Aliased']);
        $other->attachTags($this->second);
        expect(DB::table('catalog_links')->where('tag_id', $this->second->id)->value('model_type'))->toBe('test-item');
        expect($this->second->modelsOfType(TestModel::class)->get()->modelKeys())->toBe([$other->id]);
        expect(fn () => $this->second->models())->toThrow(InvalidArgumentException::class);
        expect(fn () => $this->second->modelsOfType(Model::class))->toThrow(InvalidArgumentException::class);
        expect(fn () => $this->second->modelsOfType(stdClass::class))->toThrow(InvalidArgumentException::class);
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});
