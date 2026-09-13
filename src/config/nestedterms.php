<?php

use MrNewport\LaravelNestedTerms\Models\Tag;
use MrNewport\LaravelNestedTerms\Models\Term;

return [

    // Disable before provider boot when the application owns its schema.
    'load_migrations' => true,

    // Stable slugs are opaque identities; hierarchical slugs are stored snapshots.
    'slug_mode' => 'hierarchical',
    'max_slug_length' => 255,

    'limits' => [
        'max_depth' => 256, // Number of ancestors; roots have depth zero.
        'max_descendants' => 5000, // Excludes the traversal root.
        'max_attachment_inputs' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | The database tables used by this package. You can override them here.
    |
    */
    'terms_table' => 'terms',
    'tags_table' => 'tags',
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
    'term_model' => Term::class,
    'tag_model' => Tag::class,

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
