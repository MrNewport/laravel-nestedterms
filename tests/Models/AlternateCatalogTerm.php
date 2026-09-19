<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

class AlternateCatalogTerm extends ScopedTerm
{
    protected function resolveTermsTable(): string
    {
        return 'alternate_terms';
    }

    protected function resolveTagModel(): string
    {
        return AlternateCatalogTag::class;
    }
}
