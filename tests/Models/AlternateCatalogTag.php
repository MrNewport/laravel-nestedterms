<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

use InvalidArgumentException;

class AlternateCatalogTag extends ScopedTag
{
    protected function resolveTagsTable(): string
    {
        return 'alternate_tags';
    }

    protected function resolveTermModel(): string
    {
        return AlternateCatalogTerm::class;
    }

    protected function resolveModelTagTable(string $modelClass): string
    {
        if ($modelClass !== AlternateCatalogAsset::class) {
            throw new InvalidArgumentException('This catalog supports only its declared subject.');
        }

        return 'alternate_links';
    }
}
