<?php

namespace MrNewport\LaravelNestedTerms\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelNestedTerms\Tests\TestCase;

class MigrationOptOutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nestedterms.load_migrations', false);
    }

    public function test_package_does_not_register_or_run_its_schema_when_disabled(): void
    {
        $this->assertFalse(Schema::hasTable('terms'));
        $this->assertFalse(Schema::hasTable('tags'));
        $this->assertFalse(Schema::hasTable('model_tag'));
        $this->assertSame([], app('migrator')->paths());
    }
}
