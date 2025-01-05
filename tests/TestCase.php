<?php

namespace MrNewport\LaravelNestedTerms\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MrNewport\LaravelNestedTerms\Providers\NestedTermsServiceProvider;

/**
 * Class TestCase
 *
 * Base TestCase for the NestedTerms package using Orchestra to
 * provide a minimal Laravel environment.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Get package providers (Orchestra method override).
     *
     * @param  Application  $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            NestedTermsServiceProvider::class,
        ];
    }

    /**
     * Setup the database by running package migrations, etc.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run package migrations
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
