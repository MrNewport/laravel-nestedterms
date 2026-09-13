<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates and rolls back on MySQL', function () {
    if (getenv('MYSQL_TEST_DATABASE') !== 'package_test') {
        $this->markTestSkipped('Disposable MySQL runner only.');
    }
    $originalConnection = config('database.default');
    config(['database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) (getenv('MYSQL_TEST_PORT') ?: 3306),
        'database' => 'package_test', 'username' => 'root', 'password' => getenv('MYSQL_TEST_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ]]);
    DB::purge('mysql');
    Schema::create('users', fn (Blueprint $table) => $table->id());
    try {
        expect(Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(Artisan::call('migrate:rollback', ['--database' => 'mysql', '--force' => true]))->toBe(0);
        expect(DB::table('migrations')->count())->toBe(0);
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('migrations');
        config(['database.default' => $originalConnection]);
        app('migrator')->setConnection($originalConnection);
    }
});
