<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

use MrNewport\LaravelNestedTerms\Models\Tag;

class ScopedTag extends Tag
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('tenant', fn ($query) => $query->where($query->qualifyColumn('tenant_id'), config('testing.tenant_id')));
        static::creating(fn (ScopedTag $tag) => $tag->setAttribute('tenant_id', config('testing.tenant_id')));
    }
}
