<?php

namespace MrNewport\LaravelNestedTerms\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Class NestedTermsServiceProvider
 *
 * Registers and boots the NestedTerms package within a Laravel application.
 * - Loads migrations from the package's "database/migrations" folder.
 * - Publishes a config file for customization.
 */
class NestedTermsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot(): void
    {
        // 1) Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // 2) Publish config (so users can run: php artisan vendor:publish --tag=nestedterms-config)
        $this->publishes([
            __DIR__ . '/../config/nestedterms.php' => config_path('nestedterms.php'),
        ], 'nestedterms-config');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge our package config with the app config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/nestedterms.php',
            'nestedterms'
        );
    }
}
