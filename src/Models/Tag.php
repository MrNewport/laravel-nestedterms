<?php

namespace MrNewport\LaravelNestedTerms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Class Tag
 *
 * Represents a nested item within a Term.
 * Supports infinite nesting via "parent_id" and can dynamically cast its "value"
 * based on the "type" attribute (e.g., integer, boolean, float).
 *
 * Database columns:
 * @property int $id
 * @property int $term_id
 * @property int|null $parent_id
 * @property string $slug
 * @property string|null $type
 * @property string|null $value
 * @property string $name
 * @property string|null $description
 * @property array|null $meta
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * Relationships:
 * @property-read Term $term
 * @property-read Tag|null $parent
 * @property-read Collection<int, Tag> $children
 */
class Tag extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table name is set dynamically from config.
     *
     * @var string|null
     */
    protected $table = null;

    /**
     * @var string[] Mass-assignable attributes.
     */
    protected $fillable = [
        'term_id',
        'parent_id',
        'slug',
        'type',
        'value',
        'name',
        'description',
        'meta',
        'is_active',
    ];

    /**
     * @var array<string, string> Cast definitions for Eloquent.
     */
    protected $casts = [
        'meta'      => 'array',
        'is_active' => 'boolean',
        'value'     => 'string',
    ];

    /**
     * Construct the model and set the table from config.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('nestedterms.tags_table', 'tags');
    }

    /**
     * Booted method for model events: handle slug generation, uniqueness, etc.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Tag $tag): void {
            $tag->type  = static::validateOrFallbackType($tag->type);
            $tag->slug  = $tag->generateHierarchySlug();
            $tag->slug  = static::makeSlugUnique($tag);
            $tag->value = static::prepareValue($tag->value, $tag->type);
        });

        static::updating(function (Tag $tag): void {
            if ($tag->isDirty(['term_id', 'parent_id', 'name', 'type'])) {
                $tag->type  = static::validateOrFallbackType($tag->type);
                $tag->slug  = $tag->generateHierarchySlug();
                $tag->slug  = static::makeSlugUnique($tag, $tag->id);
                $tag->value = static::prepareValue($tag->value, $tag->type);
            }
        });
    }

    /* -----------------------------------------------------------------
     |  RELATIONSHIPS
     | -----------------------------------------------------------------
     */

    /**
     * The Term this Tag belongs to.
     *
     * @return BelongsTo
     */
    public function term(): BelongsTo
    {
        $termClass = config('nestedterms.term_model', Term::class);

        return $this->belongsTo($termClass, 'term_id');
    }

    /**
     * Self-referencing: Parent Tag (if nested).
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Self-referencing: Child Tags of this Tag.
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Polymorphic pivot to which models are using this Tag.
     *
     * @return MorphToMany
     */
    public function models(): MorphToMany
    {
        // Additional config if needed, but typically we just store the pivot table name from config.
        return $this->morphedByMany(
            Model::class,
            'model',
            config('nestedterms.model_tag_table', 'model_tag'),
            'tag_id',
            'model_id'
        )->withTimestamps();
    }

    /* -----------------------------------------------------------------
     |  SCOPES
     | -----------------------------------------------------------------
     */

    /**
     * Scope a query to only active tags.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* -----------------------------------------------------------------
     |  SLUG GENERATION
     | -----------------------------------------------------------------
     */

    /**
     * Generate a hierarchical slug from parent chain + term slug + our name.
     * Example: "specifications.bedrooms.5"
     *
     * @return string
     */
    public function generateHierarchySlug(): string
    {
        $segments = [];
        $node = $this;

        while ($node) {
            $segments[] = Str::slug($node->name);
            $node = $node->parent;
        }
        // We built from child up, so reverse it
        $segments = array_reverse($segments);

        // Prepend the Term's slug if available
        if ($this->term && $this->term->slug) {
            array_unshift($segments, $this->term->slug);
        }

        return implode('.', $segments);
    }

    /**
     * Ensure (term_id, slug) uniqueness by appending a counter if needed.
     *
     * @param  Tag       $tag
     * @param  int|null  $ignoreId
     * @return string
     */
    protected static function makeSlugUnique(Tag $tag, ?int $ignoreId = null): string
    {
        $original = $tag->slug;
        $counter  = 2;

        while (static::query()
            ->where('term_id', $tag->term_id)
            ->where('slug', $tag->slug)
            ->when($ignoreId, function (Builder $q) use ($ignoreId) {
                return $q->where('id', '!=', $ignoreId);
            })
            ->exists()
        ) {
            $tag->slug = $original . '.' . $counter++;
        }

        return $tag->slug;
    }

    /* -----------------------------------------------------------------
     |  DYNAMIC CASTING
     | -----------------------------------------------------------------
     */

    /**
     * Validate or fallback the $type to recognized Eloquent cast or custom map.
     *
     * @param  string|null  $type
     * @return string
     */
    protected static function validateOrFallbackType(?string $type): string
    {
        if (! $type) {
            return 'string';
        }

        $allowed = config('nestedterms.allowed_cast_types', []);
        $map     = config('nestedterms.custom_type_map', []);

        // If it's in the allowed list, use it
        if (in_array($type, $allowed, true)) {
            return $type;
        }

        // If it's a custom domain synonym
        if (array_key_exists($type, $map)) {
            return $map[$type];
        }

        // fallback
        return 'string';
    }

    /**
     * Convert the raw $value into a DB-storable string consistent with $type.
     *
     * @param  mixed   $value
     * @param  string  $type
     * @return string
     */
    protected static function prepareValue(mixed $value, string $type): string
    {
        switch ($type) {
            case 'integer':
                return (string) ((int) $value);

            case 'boolean':
                return $value ? '1' : '0';

            case 'float':
            case 'double':
                return (string) ((float) $value);

            case 'array':
            case 'json':
            case 'object':
                return is_string($value) ? $value : json_encode($value);

            default: // 'string'
                return (string) $value;
        }
    }

    /**
     * Eloquent override to dynamically cast 'value' based on $this->type.
     *
     * @param  string  $key
     * @return string
     */
    protected function getCastType($key)
    {
        if ($key === 'value' && !empty($this->type)) {
            $allowed = config('nestedterms.allowed_cast_types', []);
            $map     = config('nestedterms.custom_type_map', []);

            if (in_array($this->type, $allowed, true)) {
                return $this->type;
            }

            return $map[$this->type] ?? 'string';
        }

        return parent::getCastType($key);
    }

    /* -----------------------------------------------------------------
     |  HELPERS
     | -----------------------------------------------------------------
     */

    /**
     * Recursively gather all descendants (children, grandchildren, etc.).
     *
     * @return Collection<int, Tag>
     */
    public function allDescendants(): Collection
    {
        $descendants = new Collection();

        foreach ($this->children as $child) {
            $descendants->push($child);

            if ($child->children->isNotEmpty()) {
                $descendants = $descendants->merge($child->allDescendants());
            }
        }

        return $descendants;
    }
}
