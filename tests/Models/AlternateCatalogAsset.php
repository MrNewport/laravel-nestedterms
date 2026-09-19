<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

class AlternateCatalogAsset extends TestModel
{
    protected $table = 'alternate_assets';

    protected function resolveTagModel(): string
    {
        return AlternateCatalogTag::class;
    }

    protected function resolveModelTagTable(): string
    {
        return 'alternate_links';
    }
}
