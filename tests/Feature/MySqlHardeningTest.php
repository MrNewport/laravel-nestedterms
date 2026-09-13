<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTag;
use MrNewport\LaravelNestedTerms\Tests\Models\ScopedTerm;
use MrNewport\LaravelNestedTerms\Tests\Models\TestModel;

it('hardens scoped attachments and typed values on disposable MySQL', function () {
    $database = getenv('MYSQL_HARDENING_DATABASE');
    if ($database === false || $database === '') {
        $this->markTestSkipped('Set MYSQL_HARDENING_DATABASE to a dedicated disposable schema.');
    }
    if (! preg_match('/\Anestedterms_hardening_[a-z0-9]{1,16}_test\z/', $database)) {
        throw new RuntimeException('Refusing a database outside the nestedterms_hardening_<run>_test allowlist.');
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
    $originalPackageConfig = config('nestedterms');
    try {
        config([
            'database.default' => 'mysql_hardening',
            'database.connections.mysql_hardening' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port,
                'database' => $database, 'username' => 'root', 'password' => $password,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ],
            'testing.tenant_id' => 1,
            'nestedterms.terms_table' => 'catalog_terms',
            'nestedterms.tags_table' => 'catalog_tags',
            'nestedterms.model_tag_table' => 'catalog_links',
            'nestedterms.term_model' => ScopedTerm::class,
            'nestedterms.tag_model' => ScopedTag::class,
            'nestedterms.slug_mode' => 'stable',
        ]);
        DB::purge('mysql_hardening');
        expect(Artisan::call('migrate', ['--database' => 'mysql_hardening', '--force' => true]))->toBe(0);
        Schema::table('catalog_terms', fn ($table) => $table->unsignedBigInteger('tenant_id')->default(1));
        Schema::table('catalog_tags', fn ($table) => $table->unsignedBigInteger('tenant_id')->default(1));
        Schema::create('test_models', function ($table) {
            $table->id();
            $table->string('name');
        });
        Relation::enforceMorphMap(['test-item' => TestModel::class]);
        $term = ScopedTerm::create(['name' => 'Current']);
        $first = ScopedTag::create(['term_id' => $term->id, 'name' => 'First']);
        $second = ScopedTag::create(['term_id' => $term->id, 'name' => 'Second']);
        $item = TestModel::create(['name' => 'Item']);
        $item->attachTags($first);
        config(['testing.tenant_id' => 2]);
        $foreignTerm = ScopedTerm::create(['name' => 'Foreign']);
        $foreign = ScopedTag::create(['term_id' => $foreignTerm->id, 'name' => 'Foreign']);
        config(['testing.tenant_id' => 1]);
        foreach ([$foreign->id, $foreign->slug, $foreign] as $input) {
            expect(fn () => $item->syncTags([$second, $input]))->toThrow(InvalidArgumentException::class);
            expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$first->id]);
        }
        expect($first->modelsOfType(TestModel::class)->first()->id)->toBe($item->id)
            ->and(DB::table('catalog_links')->value('model_type'))->toBe('test-item');
        $item->syncTags([$first->slug, $second->id, $first]);
        expect($item->tags()->count())->toBe(2);
        $item->syncTags([$first]);
        DB::unprepared("CREATE TRIGGER reject_catalog_links BEFORE INSERT ON catalog_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic pivot failure'");
        expect(fn () => $item->syncTags([$second]))->toThrow(QueryException::class);
        expect(DB::table('catalog_links')->pluck('tag_id')->all())->toBe([$first->id]);
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        foreach (['integer' => 0, 'boolean' => false] as $type => $value) {
            $tag = ScopedTag::create(['term_id' => $term->id, 'name' => $type, 'type' => $type, 'value' => null]);
            expect($tag->fresh()->value)->toBeNull();
            $tag->update(['value' => $value]);
            expect($tag->fresh()->value)->toBe($value);
            $tag->update(['value' => null]);
            expect($tag->fresh()->value)->toBeNull()
                ->and(DB::table('catalog_tags')->where('id', $tag->id)->value('value'))->toBeNull();
            expect(ScopedTag::create(['term_id' => $term->id, 'name' => $type.' initial zero', 'type' => $type, 'value' => $value])->fresh()->value)->toBe($value);
        }
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        DB::purge('mysql_hardening');
        config(['database.default' => $originalConnection, 'nestedterms' => $originalPackageConfig]);
        app('migrator')->setConnection($originalConnection);
        $server->exec('DROP DATABASE `'.$database.'`');
    }
});
