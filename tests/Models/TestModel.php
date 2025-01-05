<?php

namespace MrNewport\LaravelNestedTerms\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelNestedTerms\Traits\HasTags;

class TestModel extends Model
{
    use HasTags;

    protected $table = 'test_models';
    public $timestamps = false;
    protected $guarded = [];
}
