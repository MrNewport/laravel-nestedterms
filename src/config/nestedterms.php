<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | The database tables used by this package. You can override them here.
    |
    */
    'terms_table'     => 'terms',
    'tags_table'      => 'tags',
    'model_tag_table' => 'model_tag',

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    |
    | By default, the package uses its own Term and Tag models.
    | If you wish to extend them, specify your custom classes here.
    |
    */
    'term_model' => \MrNewport\LaravelNestedTerms\Models\Term::class,
    'tag_model'  => \MrNewport\LaravelNestedTerms\Models\Tag::class,

    /*
    |--------------------------------------------------------------------------
    | Allowed Cast Types
    |--------------------------------------------------------------------------
    |
    | We can dynamically cast the "value" column in Tag based on "type".
    | Add or remove from this list to suit your domain needs.
    |
    */
    'allowed_cast_types' => [
        'integer',
        'float',
        'double',
        'boolean',
        'string',
        'array',
        'json',
        'object',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Type Map
    |--------------------------------------------------------------------------
    |
    | If your domain uses synonyms (e.g., "number" => "integer"),
    | map them here for dynamic casting.
    |
    */
    'custom_type_map' => [
        'number' => 'integer',
    ],

];
