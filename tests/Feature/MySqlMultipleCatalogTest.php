<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogAsset;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogTag;
use MrNewport\LaravelNestedTerms\Tests\Models\AlternateCatalogTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTag;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\TestModel;

function withFreshMultipleCatalogMySql(Closure $test): void
{
    $database = getenv('MYSQL_MULTIPLE_CATALOG_DATABASE');
    if ($database === false || $database === '') {
        test()->markTestSkipped('Set MYSQL_MULTIPLE_CATALOG_DATABASE to a fresh disposable schema.');
    }
    if (! preg_match('/\Anestedterms_multicat_[a-z0-9]{1,16}_test\z/', $database)) {
        throw new RuntimeException('Refusing a database outside the nestedterms_multicat_<run>_test allowlist.');
    }
    $port = getenv('MYSQL_TEST_PORT') ?: '3306';
    if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid disposable MySQL port.');
    }
    $password = getenv('MYSQL_TEST_PASSWORD') ?: '';
    $server = new PDO('mysql:host=127.0.0.1;port='.$port, 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $existing = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existing->execute([$database]);
    if ((int) $existing->fetchColumn() !== 0) {
        throw new RuntimeException('Refusing to reuse an existing schema; provide a fresh disposable test name.');
    }
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $originalConnection = config('database.default');
    $originalPackage = config('nestedterms');
    $originalTenant = config('testing.tenant_id');
    $originalMorphMap = Relation::morphMap();
    $originalRequireMap = Relation::requiresMorphMap();
    try {
        config([
            'database.default' => 'mysql_multiple_catalogs',
            'database.connections.mysql_multiple_catalogs' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port,
                'database' => $database, 'username' => 'root', 'password' => $password,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ],
            'testing.tenant_id' => 1,
            'nestedterms.terms_table' => 'primary_terms',
            'nestedterms.tags_table' => 'primary_tags',
            'nestedterms.model_tag_table' => 'primary_links',
            'nestedterms.term_model' => ScopedTerm::class,
            'nestedterms.tag_model' => ScopedTag::class,
            'nestedterms.slug_mode' => 'stable',
        ]);
        DB::purge('mysql_multiple_catalogs');
        expect(Artisan::call('migrate', ['--database' => 'mysql_multiple_catalogs', '--force' => true]))->toBe(0);
        foreach (['primary_terms', 'primary_tags'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('tenant_id')->default(1));
        }
        Schema::create('alternate_terms', function (Blueprint $table): void {
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
        Schema::create('alternate_tags', function (Blueprint $table): void {
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
        Schema::create('alternate_links', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained('alternate_tags');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->unique(['tag_id', 'model_type', 'model_id']);
        });
        foreach (['test_models', 'alternate_assets'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
        Relation::enforceMorphMap(['primary_subject' => TestModel::class, 'alternate_subject' => AlternateCatalogAsset::class], false);
        $primaryTerm = ScopedTerm::create(['name' => 'Shared catalog', 'slug' => 'shared-catalog']);
        $alternateTerm = AlternateCatalogTerm::create(['name' => 'Shared catalog', 'slug' => 'shared-catalog']);
        $primaryTag = ScopedTag::create(['term_id' => $primaryTerm->id, 'name' => 'Shared tag', 'slug' => 'shared-tag', 'type' => 'decimal:2', 'value' => '0.00']);
        $alternateTag = AlternateCatalogTag::create(['term_id' => $alternateTerm->id, 'name' => 'Shared tag', 'slug' => 'shared-tag', 'type' => 'decimal:2', 'value' => '999999999999999999.25']);
        $primary = TestModel::create(['name' => 'Primary subject']);
        $alternate = AlternateCatalogAsset::create(['name' => 'Alternate subject']);
        $test(compact('primaryTerm', 'alternateTerm', 'primaryTag', 'alternateTag', 'primary', 'alternate'));
    } finally {
        Relation::morphMap($originalMorphMap, false);
        Relation::requireMorphMap($originalRequireMap);
        DB::purge('mysql_multiple_catalogs_alias');
        DB::purge('mysql_multiple_catalogs');
        config(['database.default' => $originalConnection, 'nestedterms' => $originalPackage, 'testing.tenant_id' => $originalTenant]);
        app('migrator')->setConnection($originalConnection);
        $server->exec('DROP DATABASE `'.$database.'`');
    }
}

it('keeps simultaneous native MySQL catalogs and inverse aliases independent with overlapping identities', function () {
    withFreshMultipleCatalogMySql(function (array $fixture): void {
        extract($fixture);
        $configuration = config('nestedterms');
        expect(fn () => withFreshMultipleCatalogMySql(fn () => null))
            ->toThrow(RuntimeException::class, 'Refusing to reuse an existing schema');
        expect(Schema::hasTable('primary_tags'))->toBeTrue()
            ->and(Schema::hasTable('alternate_tags'))->toBeTrue();
        $primary->attachTags($primaryTag);
        $alternate->attachTags($alternateTag);
        expect($primaryTerm->id)->toBe($alternateTerm->id)
            ->and($primaryTerm->slug)->toBe($alternateTerm->slug)
            ->and($primaryTag->id)->toBe($alternateTag->id)
            ->and($primaryTag->slug)->toBe($alternateTag->slug)
            ->and($primary->id)->toBe($alternate->id)
            ->and($primary->tags()->sole())->toBeInstanceOf(ScopedTag::class)
            ->and($alternate->tags()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
            ->and($alternateTag->term()->sole())->toBeInstanceOf(AlternateCatalogTerm::class)
            ->and($alternateTerm->tags()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
            ->and($primary->tagsByTerm($primaryTerm->slug)->sole()->value)->toBe('0.00')
            ->and($alternate->tagsByTerm($alternateTerm->id)->sole()->value)->toBe('999999999999999999.25')
            ->and($primaryTag->modelsOfType(TestModel::class)->sole()->id)->toBe($primary->id)
            ->and($alternateTag->modelsOfType(AlternateCatalogAsset::class)->sole()->id)->toBe($alternate->id)
            ->and(DB::table('primary_links')->sole()->model_type)->toBe('primary_subject')
            ->and(DB::table('alternate_links')->sole()->model_type)->toBe('alternate_subject')
            ->and(config('nestedterms'))->toBe($configuration);
        expect(fn () => $alternateTag->modelsOfType(TestModel::class))->toThrow(InvalidArgumentException::class);
        $child = AlternateCatalogTag::create(['term_id' => $alternateTerm->id, 'parent_id' => $alternateTag->id, 'name' => 'Child']);
        expect($child->parent()->sole())->toBeInstanceOf(AlternateCatalogTag::class)
            ->and($child->ancestors()->modelKeys())->toBe([$alternateTag->id])
            ->and($alternateTag->allDescendants()->modelKeys())->toBe([$child->id])
            ->and($primaryTag->allDescendants())->toHaveCount(0);
    });
});

it('rejects foreign models and current tenant scope violations without detaching native MySQL links', function () {
    withFreshMultipleCatalogMySql(function (array $fixture): void {
        extract($fixture);
        $primary->attachTags($primaryTag);
        $alternate->attachTags($alternateTag);
        $before = DB::table('alternate_links')->get()->toJson();
        foreach (['attachTags', 'detachTags', 'syncTags'] as $method) {
            expect(fn () => $alternate->$method([$alternateTag, $primaryTag]))->toThrow(InvalidArgumentException::class);
            expect(DB::table('alternate_links')->get()->toJson())->toBe($before);
        }
        expect($primary->hasTag($alternateTag))->toBeFalse()
            ->and($alternate->hasTag($primaryTag))->toBeFalse()
            ->and($alternate->hasTag($alternateTag->replicate()))->toBeFalse();
        $forged = clone $alternateTag;
        $forged->setTable('primary_tags');
        expect($alternate->hasTag($forged))->toBeFalse();
        expect(fn () => $alternate->syncTags($forged))->toThrow(InvalidArgumentException::class);
        config(['testing.tenant_id' => 2]);
        $foreignTerm = AlternateCatalogTerm::create(['name' => 'Foreign']);
        $foreign = AlternateCatalogTag::create(['term_id' => $foreignTerm->id, 'name' => 'Foreign']);
        expect($primary->hasTag($primaryTag))->toBeFalse()->and($alternate->hasTag($alternateTag))->toBeFalse();
        config(['testing.tenant_id' => 1]);
        foreach ([$foreign->id, $foreign->slug, $foreign] as $input) {
            expect(fn () => $alternate->syncTags([$alternateTag, $input]))->toThrow(InvalidArgumentException::class);
            expect(DB::table('alternate_links')->get()->toJson())->toBe($before);
        }
        expect($alternate->hasTag($alternateTag))->toBeTrue()
            ->and(DB::table('primary_links')->pluck('tag_id')->all())->toBe([$primaryTag->id])
            ->and(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    });
});

it('round trips exact decimals and typed null zero false through both native MySQL catalogs', function () {
    withFreshMultipleCatalogMySql(function (array $fixture): void {
        extract($fixture);
        foreach ([[$primary, $primaryTerm, ScopedTag::class, 'primary_tags'], [$alternate, $alternateTerm, AlternateCatalogTag::class, 'alternate_tags']] as [$asset, $term, $tagClass, $table]) {
            $decimal = $tagClass::create(['term_id' => $term->id, 'name' => 'Exact measurement', 'type' => 'decimal:2', 'value' => '9007199254740993.25']);
            $zero = $tagClass::create(['term_id' => $term->id, 'name' => 'Zero', 'type' => 'integer', 'value' => 0]);
            $false = $tagClass::create(['term_id' => $term->id, 'name' => 'False', 'type' => 'boolean', 'value' => false]);
            $asset->syncTags([$decimal->id, $zero->slug, $false]);
            expect($asset->tags()->findOrFail($decimal->id)->value)->toBe('9007199254740993.25')
                ->and(DB::table($table)->where('id', $decimal->id)->value('value'))->toBe('9007199254740993.25')
                ->and($asset->tags()->findOrFail($zero->id)->value)->toBe(0)
                ->and($asset->tags()->findOrFail($false->id)->value)->toBeFalse();
            foreach ([$decimal, $zero, $false] as $tag) {
                $tag->update(['value' => null]);
                expect($tag->fresh()->value)->toBeNull()->and(DB::table($table)->where('id', $tag->id)->value('value'))->toBeNull();
            }
            $decimal->update(['value' => '-0.00']);
            expect($decimal->fresh()->value)->toBe('0.00');
            $decimal->update(['type' => 'decimal:1', 'value' => '1.50']);
            expect($decimal->fresh()->type)->toBe('decimal:1')->and($decimal->fresh()->value)->toBe('1.5');
            expect(fn () => $decimal->update(['value' => '1.51']))->toThrow(InvalidArgumentException::class);
            expect($decimal->fresh()->value)->toBe('1.5');
            $asset->detachTags([$zero->id, $false->slug]);
            expect($asset->tags()->get()->modelKeys())->toBe([$decimal->id]);
        }
    });
});

it('rolls back native MySQL pivot and final commit failures without affecting the other catalog', function () {
    withFreshMultipleCatalogMySql(function (array $fixture): void {
        extract($fixture);
        $primary->attachTags($primaryTag);
        $alternate->attachTags($alternateTag);
        $other = AlternateCatalogTag::create(['term_id' => $alternateTerm->id, 'name' => 'Replacement']);
        $before = DB::table('alternate_links')->get()->toJson();
        DB::unprepared("CREATE TRIGGER reject_alternate_links BEFORE INSERT ON alternate_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic alternate pivot failure'");
        expect(fn () => $alternate->syncTags($other))->toThrow(QueryException::class);
        expect(DB::table('alternate_links')->get()->toJson())->toBe($before)
            ->and(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        DB::unprepared('DROP TRIGGER reject_alternate_links');
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $observed = false;
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$observed, $other, $primaryTag): void {
            if ($event->connection->getName() === 'mysql_multiple_catalogs') {
                expect(DB::table('alternate_links')->pluck('tag_id')->all())->toBe([$other->id])
                    ->and(DB::table('primary_links')->pluck('tag_id')->all())->toBe([$primaryTag->id]);
                $observed = true;
                throw new RuntimeException('Synthetic native commit failure');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            expect(fn () => $alternate->syncTags($other))->toThrow(RuntimeException::class, 'Synthetic native commit failure');
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
        expect($observed)->toBeTrue()
            ->and(DB::table('alternate_links')->get()->toJson())->toBe($before)
            ->and(DB::table('primary_links')->pluck('tag_id')->all())->toBe([$primaryTag->id])
            ->and($connection->transactionLevel())->toBe(0)
            ->and($connection->getPdo()->inTransaction())->toBeFalse();
        $alternate->syncTags($other);
        expect($alternate->tags()->get()->modelKeys())->toBe([$other->id])
            ->and($primary->tags()->get()->modelKeys())->toBe([$primaryTag->id]);
    });
});

it('rejects tag model connections even when both native MySQL connections reach the same schema', function () {
    withFreshMultipleCatalogMySql(function (array $fixture): void {
        extract($fixture);
        $alternate->attachTags($alternateTag);
        config(['database.connections.mysql_multiple_catalogs_alias' => config('database.connections.mysql_multiple_catalogs')]);
        DB::purge('mysql_multiple_catalogs_alias');
        expect(DB::connection('mysql_multiple_catalogs_alias')->getPdo())->not->toBe(DB::connection()->getPdo())
            ->and(DB::connection('mysql_multiple_catalogs_alias')->getDatabaseName())->toBe(DB::connection()->getDatabaseName());
        $foreignConnection = AlternateCatalogTag::on('mysql_multiple_catalogs_alias')->findOrFail($alternateTag->id);
        expect($alternate->hasTag($foreignConnection))->toBeFalse();
        foreach (['attachTags', 'detachTags', 'syncTags'] as $method) {
            expect(fn () => $alternate->$method($foreignConnection))->toThrow(InvalidArgumentException::class);
            expect(DB::table('alternate_links')->pluck('tag_id')->all())->toBe([$alternateTag->id]);
        }
        expect($alternate->hasTag($alternateTag))->toBeTrue()
            ->and(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
            ->and(DB::connection('mysql_multiple_catalogs_alias')->getPdo()->inTransaction())->toBeFalse();
    });
});
