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
use MrNewport\LaravelNestedTerms\Support\ExactDecimal;

/**
 * Class Tag
 *
 * Represents a nested item within a Term.
 * Supports bounded nesting via "parent_id" and can dynamically cast its "value"
 * based on the "type" attribute (e.g., integer, boolean, float).
 *
 * Database columns:
 *
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
        'meta' => 'array',
        'is_active' => 'boolean',
        'value' => 'string',
    ];

    /**
     * Construct the model and set the table from config.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = $this->resolveTagsTable();
    }

    protected function resolveTagsTable(): string
    {
        return config('nestedterms.tags_table', 'tags');
    }

    /** @return class-string<Term> */
    protected function resolveTermModel(): string
    {
        return config('nestedterms.term_model', Term::class);
    }

    /** @param class-string<Model> $modelClass */
    protected function resolveModelTagTable(string $modelClass): string
    {
        return config('nestedterms.model_tag_table', 'model_tag');
    }

    /**
     * Booted method for model events: handle slug generation, uniqueness, etc.
     */
    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $type = static::validateOrFallbackType($tag->type);
            $scale = static::decimalScale($type);
            $raw = $tag->attributes['value'] ?? null;
            if ($scale !== null) {
                $tag->attributes['value'] = $raw === null ? null : ExactDecimal::normalize($raw, $scale);
            } elseif ($type === 'integer' && static::decimalScale($tag->getRawOriginal('type')) !== null && $raw !== null) {
                $integer = ExactDecimal::normalize($raw, 0);
                if (filter_var($integer, FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('The decimal value cannot be represented as a PHP integer.');
                }
                $tag->attributes['value'] = $integer;
            }
        });

        static::creating(function (Tag $tag): void {
            $tag->validateHierarchy();
            $tag->type = static::validateOrFallbackType($tag->type);
            $tag->slug = $tag->resolveSlug(true);
            $tag->attributes['value'] = static::prepareValue($tag->value, $tag->type);
        });

        static::updating(function (Tag $tag): void {
            if ($tag->isDirty(['term_id', 'parent_id', 'name', 'type'])) {
                $tag->validateHierarchy();
                $tag->type = static::validateOrFallbackType($tag->type);
                $tag->slug = $tag->resolveSlug(false);
            } elseif ($tag->isDirty('slug')) {
                if (config('nestedterms.slug_mode', 'hierarchical') === 'stable') {
                    throw new \InvalidArgumentException('Stable tag slugs cannot be changed after creation.');
                }
                static::assertSlugLength($tag->slug);
            }
            if ($tag->isDirty(['value', 'type'])) {
                $tag->attributes['value'] = static::prepareValue($tag->value, $tag->type);
            }
        });
    }

    private function validateHierarchy(): void
    {
        $this->term()->firstOrFail();
        $ancestors = $this->ancestors();
        if ($this->exists && $this->isDirty('term_id') && $this->children()->where('term_id', '!=', $this->term_id)->exists()) {
            throw new \InvalidArgumentException('A tag and its children must belong to the same term.');
        }
        if ($this->exists && $this->isDirty('parent_id')) {
            $remainingDepth = static::traversalLimit('max_depth', null) - $ancestors->count();
            if ($remainingDepth === 0) {
                if ($this->children()->exists()) {
                    throw new \InvalidArgumentException('Moving this tag would exceed the configured depth limit.');
                }
            } else {
                $this->allDescendants($remainingDepth);
            }
        }
    }

    private function resolveSlug(bool $creating): string
    {
        $mode = config('nestedterms.slug_mode', 'hierarchical');
        if (! in_array($mode, ['hierarchical', 'stable'], true)) {
            throw new \InvalidArgumentException('Unknown tag slug mode.');
        }
        if ($mode === 'stable') {
            if (! $creating) {
                if ($this->isDirty('slug')) {
                    throw new \InvalidArgumentException('Stable tag slugs cannot be changed after creation.');
                }
                static::assertSlugLength($this->slug);

                return $this->slug;
            }
            $this->slug = $this->slug === null || $this->slug === '' ? bin2hex(random_bytes(16)) : $this->slug;
        } else {
            $this->slug = $this->generateHierarchySlug();
        }

        return static::makeSlugUnique($this, $creating ? null : $this->getKey());
    }

    private static function assertSlugLength(string $slug): void
    {
        $limit = (int) config('nestedterms.max_slug_length', 255);
        if ($limit < 1 || $slug === '' || mb_strlen($slug) > $limit) {
            throw new \LengthException('The tag slug exceeds the configured storage length or is empty.');
        }
    }

    /* -----------------------------------------------------------------
     |  RELATIONSHIPS
     | -----------------------------------------------------------------
     */

    /**
     * The Term this Tag belongs to.
     */
    public function term(): BelongsTo
    {
        $termClass = $this->resolveTermModel();
        if (! is_a($termClass, Term::class, true) || (new \ReflectionClass($termClass))->isAbstract()) {
            throw new \InvalidArgumentException('The related term model must be a concrete Term subclass.');
        }

        return $this->belongsTo($termClass, 'term_id');
    }

    /**
     * Self-referencing: Parent Tag (if nested).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * Self-referencing: Child Tags of this Tag.
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * Polymorphic pivot to which models are using this Tag.
     */
    public function models(): MorphToMany
    {
        throw new \InvalidArgumentException('Specify the related model class with modelsOfType().');
    }

    /** @param class-string<Model> $modelClass */
    public function modelsOfType(string $modelClass): MorphToMany
    {
        if (! is_subclass_of($modelClass, Model::class) || (new \ReflectionClass($modelClass))->isAbstract()) {
            throw new \InvalidArgumentException('The inverse relationship requires a concrete Eloquent model class.');
        }

        return $this->morphedByMany($modelClass, 'model', $this->resolveModelTagTable($modelClass), 'tag_id', 'model_id')->withTimestamps();
    }

    /* -----------------------------------------------------------------
     |  SCOPES
     | -----------------------------------------------------------------
     */

    /**
     * Scope a query to only active tags.
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
     */
    public function generateHierarchySlug(): string
    {
        $segments = $this->ancestors()->reverse()->map(fn (Tag $tag): string => Str::slug($tag->name))->all();
        $segments[] = Str::slug($this->name);
        if (($term = $this->term()->first()) && $term->slug) {
            array_unshift($segments, $term->slug);
        }

        return implode('.', $segments);
    }

    /** @return Collection<int, static> Nearest ancestors first; never uses a stale loaded relation. */
    public function ancestors(?int $maxDepth = null): Collection
    {
        $limit = static::traversalLimit('max_depth', $maxDepth);
        $ancestors = new Collection;
        $seen = $this->exists ? [$this->getKey() => true] : [];
        $parentId = $this->parent_id;
        while ($parentId !== null) {
            if (isset($seen[$parentId]) || $ancestors->count() >= $limit) {
                throw new \InvalidArgumentException('Tag ancestry contains a cycle or exceeds the configured depth limit.');
            }
            $parent = $this->newQuery()->findOrFail($parentId);
            if ((string) $parent->term_id !== (string) $this->term_id) {
                throw new \InvalidArgumentException('Parent tags must belong to the same term.');
            }
            $seen[$parentId] = true;
            $ancestors->push($parent);
            $parentId = $parent->parent_id;
        }

        return $ancestors;
    }

    private static function traversalLimit(string $key, ?int $requested): int
    {
        $configured = (int) config('nestedterms.limits.'.$key, $key === 'max_depth' ? 256 : 5000);
        if ($configured < 1 || ($requested !== null && $requested < 1)) {
            throw new \InvalidArgumentException('Tag traversal limits must be positive.');
        }

        return min($configured, $requested ?? $configured);
    }

    /**
     * Ensure (term_id, slug) uniqueness by appending a counter if needed.
     */
    protected static function makeSlugUnique(Tag $tag, ?int $ignoreId = null): string
    {
        $original = $tag->slug;
        $counter = 2;
        static::assertSlugLength($tag->slug);

        while (static::withTrashed()
            ->where('term_id', $tag->term_id)
            ->where('slug', $tag->slug)
            ->when($ignoreId, function (Builder $q) use ($ignoreId) {
                return $q->where('id', '!=', $ignoreId);
            })
            ->exists()
        ) {
            $tag->slug = $original.'.'.$counter++;
            static::assertSlugLength($tag->slug);
        }

        return $tag->slug;
    }

    /* -----------------------------------------------------------------
     |  DYNAMIC CASTING
     | -----------------------------------------------------------------
     */

    /**
     * Validate or fallback the $type to recognized Eloquent cast or custom map.
     */
    protected static function validateOrFallbackType(?string $type): string
    {
        if (! $type) {
            return 'string';
        }

        $allowed = config('nestedterms.allowed_cast_types', []);
        $map = config('nestedterms.custom_type_map', []);

        if (static::decimalScale($type) !== null && ! in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('This exact decimal type is not enabled in the package configuration.');
        }

        // If it's in the allowed list, use it
        if (in_array($type, $allowed, true)) {
            return $type;
        }

        // If it's a custom domain synonym
        if (array_key_exists($type, $map)) {
            if (static::decimalScale($map[$type]) !== null && ! in_array($map[$type], $allowed, true)) {
                throw new \InvalidArgumentException('The mapped exact decimal type is not enabled in the package configuration.');
            }

            return $map[$type];
        }

        // fallback
        return 'string';
    }

    /**
     * Convert the raw $value into a DB-storable string consistent with $type.
     */
    protected static function prepareValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        if (($scale = static::decimalScale($type)) !== null) {
            return ExactDecimal::normalize($value, $scale);
        }
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
        if ($key === 'value' && ! empty($this->type)) {
            $type = static::validateOrFallbackType($this->type);

            return static::decimalScale($type) !== null ? 'string' : $type;
        }

        return parent::getCastType($key);
    }

    /** Exact decimals are strings; never invoke Laravel's rounding decimal cast. */
    protected function castAttribute($key, $value)
    {
        if ($key === 'value' && ($scale = static::decimalScale(static::validateOrFallbackType($this->type))) !== null) {
            return $value === null ? null : ExactDecimal::normalize($value, $scale);
        }

        return parent::castAttribute($key, $value);
    }

    /** Comparing an old decimal at a new scale can round or reject a valid replacement. */
    public function originalIsEquivalent($key)
    {
        if ($key === 'value' && (static::decimalScale(static::validateOrFallbackType($this->attributes['type'] ?? null)) !== null
            || static::decimalScale(static::validateOrFallbackType($this->original['type'] ?? null)) !== null)) {
            return array_key_exists($key, $this->original)
                && ($this->attributes[$key] ?? null) === $this->original[$key];
        }

        return parent::originalIsEquivalent($key);
    }

    private static function decimalScale(?string $type): ?int
    {
        if ($type === null || ! str_starts_with(strtolower(ltrim($type)), 'decimal')) {
            return null;
        }
        if (! preg_match('/\Adecimal:([0-6])\z/', $type, $matches)) {
            throw new \InvalidArgumentException('Use an exact decimal type from decimal:0 through decimal:6.');
        }

        return (int) $matches[1];
    }

    /* -----------------------------------------------------------------
     |  HELPERS
     | -----------------------------------------------------------------
     */

    /**
     * Gather descendants breadth first, within the configured depth and node limits.
     *
     * @return Collection<int, Tag>
     */
    public function allDescendants(?int $maxDepth = null, ?int $maxNodes = null): Collection
    {
        if (! $this->exists) {
            throw new \InvalidArgumentException('Save the tag before traversing its descendants.');
        }
        $depthLimit = static::traversalLimit('max_depth', $maxDepth);
        $nodeLimit = static::traversalLimit('max_descendants', $maxNodes);
        $descendants = new Collection;
        $seen = [$this->getKey() => true];
        $frontier = [$this->getKey()];
        $depth = 0;
        while ($frontier !== []) {
            $depth++;
            $next = [];
            foreach (array_chunk($frontier, 250) as $parents) {
                $children = $this->newQuery()->whereIn('parent_id', $parents)->orderBy($this->qualifyColumn('id'))
                    ->limit($nodeLimit - $descendants->count() + 1)->get();
                foreach ($children as $child) {
                    if (isset($seen[$child->getKey()]) || $depth > $depthLimit || $descendants->count() >= $nodeLimit
                        || (string) $child->term_id !== (string) $this->term_id) {
                        throw new \InvalidArgumentException('Tag descendants contain a cycle, cross-term parent, or exceed the configured traversal limits.');
                    }
                    $seen[$child->getKey()] = true;
                    $descendants->push($child);
                    $next[] = $child->getKey();
                }
            }
            $frontier = $next;
        }

        return $descendants;
    }
}
