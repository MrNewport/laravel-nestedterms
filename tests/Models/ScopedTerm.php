<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

use MrNewport\LaravelNestedTerms\Models\Term;

class ScopedTerm extends Term
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('tenant', fn ($query) => $query->where($query->qualifyColumn('tenant_id'), config('testing.tenant_id')));
        static::creating(fn (ScopedTerm $term) => $term->setAttribute('tenant_id', config('testing.tenant_id')));
    }
}
