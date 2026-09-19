<?php

namespace MrNewport\LaravelNestedTerms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Class Term
 *
 * Represents a high-level taxonomy bucket (e.g., "Specifications", "Amenities").
 * Each Term can have multiple Tags, which may be nested infinitely.
 *
 * Database columns:
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property array|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * Relationships:
 * @property-read Collection<int, Tag> $tags
 */
class Term extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table name is set dynamically from config.
     *
     * @var string|null
     */
    protected $table = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'meta',
        'is_active',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'meta'      => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Construct the model and set the table from config.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = $this->resolveTermsTable();
    }

    protected function resolveTermsTable(): string
    {
        return config('nestedterms.terms_table', 'terms');
    }

    /** @return class-string<Tag> */
    protected function resolveTagModel(): string
    {
        return config('nestedterms.tag_model', Tag::class);
    }

    /**
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Term $term) {
            // If slug not provided, auto-generate
            $term->generateSlug();
        });

        static::updating(function (Term $term) {
            // If the name changed, regenerate slug
            if ($term->isDirty('name')) {
                $term->generateSlug();
            }
        });
    }

    /**
     * Relationship: A Term can have many Tags belonging to it.
     *
     * @return HasMany
     */
    public function tags(): HasMany
    {
        $tagClass = $this->resolveTagModel();
        if (! is_a($tagClass, Tag::class, true) || (new \ReflectionClass($tagClass))->isAbstract()) {
            throw new \InvalidArgumentException('The related tag model must be a concrete Tag subclass.');
        }

        return $this->hasMany($tagClass, 'term_id');
    }

    /**
     * Scope a query to only include active terms.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Example: Generate a slug from 'name' if empty.
     *
     * @return void
     */
    public function generateSlug(): void
    {
        if (empty($this->slug) && !empty($this->name)) {
            $this->slug = Str::slug($this->name);
        }
    }
}
