<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogAsset;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogTag;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTag;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\TestModel;

beforeEach(function () {
    foreach (['terms', 'tags'] as $name) {
        Schema::table($name, fn ($table) => $table->unsignedBigInteger('tenant_id')->default(1));
    }
    Schema::create('alternate_terms', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(1);
        $table->string('name');
        $table->string('slug')->unique();
        $table->text('description')->nullable();
        $table->json('meta')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('alternate_tags', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->default(1);
        $table->foreignId('term_id')->constrained('alternate_terms');
        $table->foreignId('parent_id')->nullable()->constrained('alternate_tags');
        $table->string('name');
        $table->string('slug')->unique();
        $table->text('description')->nullable();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->json('meta')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('alternate_links', function ($table) {
        $table->foreignId('tag_id')->constrained('alternate_tags');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->timestamps();
        $table->unique(['tag_id', 'model_type', 'model_id']);
    });
    foreach (['test_models', 'alternate_assets'] as $name) {
        Schema::create($name, function ($table) {
            $table->id();
            $table->string('name');
        });
    }
    config([
        'testing.tenant_id' => 1,
        'nestedterms.term_model' => ScopedTerm::class,
        'nestedterms.tag_model' => ScopedTag::class,
        'nestedterms.slug_mode' => 'stable',
    ]);
    Relation::morphMap(['primary_subject' => TestModel::class, 'alternate_subject' => AlternateCatalogAsset::class]);
    $this->primaryTerm = ScopedTerm::create(['name' => 'Shared catalog']);
    $this->alternateTerm = AlternateCatalogTerm::create(['name' => 'Shared catalog']);
    $this->primaryTag = ScopedTag::create(['term_id' => $this->primaryTerm->id, 'name' => 'Shared tag', 'slug' => 'shared-tag', 'type' => 'decimal:2', 'value' => '0.00']);
    $this->alternateTag = AlternateCatalogTag::create(['term_id' => $this->alternateTerm->id, 'name' => 'Shared tag', 'slug' => 'shared-tag', 'type' => 'decimal:2', 'value' => '999999999999999999.25']);
    $this->primary = TestModel::create(['name' => 'Primary subject']);
    $this->alternate = AlternateCatalogAsset::create(['name' => 'Alternate subject']);
});

afterEach(fn () => Relation::morphMap([], false));

it('uses two concrete catalogs simultaneously without changing catalog configuration', function () {
    $configuration = config('nestedterms');
    $this->primary->attachTags($this->primaryTag);
    $this->alternate->attachTags($this->alternateTag);
    expect($this->primaryTag->id)->toBe($this->alternateTag->id)
        ->and($this->primaryTag->slug)->toBe($this->alternateTag->slug)
        ->and($this->primaryTerm->id)->toBe($this->alternateTerm->id)
        ->and($this->primary->id)->toBe($this->alternate->id)
        ->and($this->primary->tags()->sole())->toBeInstanceOf(ScopedTag::class)
        ->and($this->alternate->tags()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
        ->and($this->alternateTag->term()->sole())->toBeInstanceOf(AlternateCatalogTerm::class)
        ->and($this->alternateTerm->tags()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
        ->and($this->primary->tagsByTerm($this->primaryTerm->slug)->sole()->value)->toBe('0.00')
        ->and($this->alternate->tagsByTerm($this->alternateTerm->slug)->sole()->value)->toBe('999999999999999999.25')
        ->and($this->primaryTag->modelsOfType(TestModel::class)->sole()->id)->toBe($this->primary->id)
        ->and($this->alternateTag->modelsOfType(AlternateCatalogAsset::class)->sole()->id)->toBe($this->alternate->id)
        ->and(DB::table('model_tag')->sole()->model_type)->toBe('primary_subject')
        ->and(DB::table('alternate_links')->sole()->model_type)->toBe('alternate_subject')
        ->and(config('nestedterms'))->toBe($configuration);
    expect(fn () => $this->alternateTag->modelsOfType(TestModel::class))->toThrow(InvalidArgumentException::class);
});

it('keeps model-based membership checks inside the declared catalog even when IDs overlap', function () {
    $this->primary->attachTags($this->primaryTag);
    $this->alternate->attachTags($this->alternateTag);
    expect($this->primary->hasTag($this->primaryTag))->toBeTrue()
        ->and($this->alternate->hasTag($this->alternateTag))->toBeTrue()
        ->and($this->primary->hasTag($this->alternateTag))->toBeFalse()
        ->and($this->alternate->hasTag($this->primaryTag))->toBeFalse()
        ->and($this->alternate->hasTag($this->alternateTag->replicate()))->toBeFalse();
    $this->alternateTag->setTable('tags');
    expect($this->alternate->hasTag($this->alternateTag))->toBeFalse();
});

it('rejects a foreign catalog model atomically for every attachment mutation', function ($method) {
    $this->primary->attachTags($this->primaryTag);
    $this->alternate->attachTags($this->alternateTag);
    $before = DB::table('alternate_links')->get()->toJson();
    expect(fn () => $this->alternate->{$method}([$this->alternateTag, $this->primaryTag]))->toThrow(InvalidArgumentException::class);
    expect(DB::table('alternate_links')->get()->toJson())->toBe($before)
        ->and(DB::table('model_tag')->pluck('tag_id')->all())->toBe([$this->primaryTag->id]);
})->with(['attachTags', 'detachTags', 'syncTags']);

it('resolves IDs and slugs through the subject catalog and retains false zero and null exactly', function () {
    $zero = AlternateCatalogTag::create(['term_id' => $this->alternateTerm->id, 'name' => 'Zero', 'type' => 'integer', 'value' => 0]);
    $false = AlternateCatalogTag::create(['term_id' => $this->alternateTerm->id, 'name' => 'False', 'type' => 'boolean', 'value' => false]);
    $this->alternate->syncTags([$this->alternateTag->id, $zero->slug, $false]);
    expect($this->alternate->tags()->count())->toBe(3)
        ->and($zero->fresh()->value)->toBe(0)->and($false->fresh()->value)->toBeFalse()
        ->and(DB::table('model_tag')->count())->toBe(0);
    $this->alternateTag->update(['value' => null]);
    expect($this->alternate->tags()->findOrFail($this->alternateTag->id)->value)->toBeNull();
    $this->alternate->detachTags([$zero->id, $false->slug]);
    expect($this->alternate->tags()->get()->modelKeys())->toBe([$this->alternateTag->id]);
});

it('re-resolves current tenant scope for both catalogs without changing their configuration', function () {
    $this->primary->attachTags($this->primaryTag);
    $this->alternate->attachTags($this->alternateTag);
    config(['testing.tenant_id' => 2]);
    expect($this->primary->hasTag($this->primaryTag))->toBeFalse()
        ->and($this->alternate->hasTag($this->alternateTag))->toBeFalse();
    expect(fn () => $this->alternate->syncTags($this->alternateTag->id))->toThrow(InvalidArgumentException::class);
    config(['testing.tenant_id' => 1]);
    expect($this->alternate->hasTag($this->alternateTag))->toBeTrue();
});

it('keeps hierarchy traversal within its concrete term and tag models', function () {
    $child = AlternateCatalogTag::create(['term_id' => $this->alternateTerm->id, 'parent_id' => $this->alternateTag->id, 'name' => 'Child']);
    expect($child->parent()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
        ->and($child->ancestors()->modelKeys())->toBe([$this->alternateTag->id])
        ->and($this->alternateTag->allDescendants()->modelKeys())->toBe([$child->id])
        ->and($this->primaryTag->allDescendants())->toHaveCount(0);
    expect(fn () => $this->alternateTag->update(['parent_id' => $child->id]))->toThrow(InvalidArgumentException::class);
});

it('rolls back alternate pivot changes without touching the primary catalog', function () {
    $this->primary->attachTags($this->primaryTag);
    $this->alternate->attachTags($this->alternateTag);
    $other = AlternateCatalogTag::create(['term_id' => $this->alternateTerm->id, 'name' => 'Other']);
    DB::unprepared("CREATE TRIGGER reject_alternate_link BEFORE INSERT ON alternate_links BEGIN SELECT RAISE(ABORT, 'synthetic alternate pivot failure'); END");
    expect(fn () => $this->alternate->syncTags($other))->toThrow(QueryException::class);
    expect(DB::table('alternate_links')->pluck('tag_id')->all())->toBe([$this->alternateTag->id])
        ->and(DB::table('model_tag')->pluck('tag_id')->all())->toBe([$this->primaryTag->id]);
});

it('rejects a concrete tag model from another connection before membership or mutation', function () {
    $this->alternate->attachTags($this->alternateTag);
    config(['database.connections.alias' => config('database.connections.testing')]);
    $this->alternateTag->setConnection('alias');
    expect($this->alternate->hasTag($this->alternateTag))->toBeFalse();
    expect(fn () => $this->alternate->syncTags($this->alternateTag))->toThrow(InvalidArgumentException::class);
    expect(DB::table('alternate_links')->count())->toBe(1);
});

it('validates the concrete model chosen by each relation hook', function () {
    $term = new class extends Term
    {
        protected function resolveTagModel(): string
        {
            return stdClass::class;
        }
    };
    $tag = new class extends Tag
    {
        protected function resolveTermModel(): string
        {
            return Model::class;
        }
    };
    $asset = new class extends TestModel
    {
        protected function resolveTagModel(): string
        {
            return Model::class;
        }
    };
    expect(fn () => $term->tags())->toThrow(InvalidArgumentException::class);
    expect(fn () => $tag->term())->toThrow(InvalidArgumentException::class);
    expect(fn () => $asset->tags())->toThrow(InvalidArgumentException::class);
});
