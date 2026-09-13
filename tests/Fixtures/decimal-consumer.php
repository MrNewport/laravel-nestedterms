<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;
use MrNewport\LaravelNestedTerms\Support\ExactDecimal;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'sqlite'
    || ! str_ends_with(DB::connection()->getDatabaseName(), '/nestedterms_consumer_test.sqlite')
    || class_exists('Pest\TestSuite') || class_exists('PHPUnit\Framework\TestCase')) {
    throw new RuntimeException('This check requires a dedicated SQLite consumer database and production dependencies only.');
}
$assert = static function (bool $condition): void {
    if (! $condition) {
        throw new RuntimeException('The exact decimal production consumer check failed.');
    }
};
$assert(ExactDecimal::normalize('999999999999999999.25', 2) === '999999999999999999.25');
DB::beginTransaction();
try {
    $term = Term::create(['name' => 'Synthetic exact decimal consumer']);
    $tag = Tag::create(['term_id' => $term->id, 'name' => 'Exact', 'type' => 'decimal:2', 'value' => '999999999999999999.25']);
    $assert($tag->fresh()->value === '999999999999999999.25');
    foreach (['1.251', 1.25] as $invalid) {
        try {
            $tag->update(['value' => $invalid]);
            throw new RuntimeException('The consumer accepted an inexact decimal command.');
        } catch (InvalidArgumentException) {
            $assert($tag->fresh()->value === '999999999999999999.25');
        }
    }
    $tag->update(['value' => '1.20', 'type' => 'decimal:1']);
    $assert($tag->fresh()->value === '1.2');
    $assert(DB::table($tag->getTable())->where('id', $tag->id)->value('value') === '1.2');
    $tag->update(['value' => null]);
    $assert($tag->fresh()->value === null);
} finally {
    DB::rollBack();
}
echo "Exact decimal production consumer passed.\n";
