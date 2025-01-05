<?php

namespace MrNewport\LaravelNestedTerms\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use MrNewport\LaravelNestedTerms\Models\Tag;

/**
 * Trait HasTags
 *
 * Allows an Eloquent model to have many "tags" via a polymorphic pivot.
 * Includes helper methods: attachTags, detachTags, syncTags, hasTag,
 * and tagsByTerm for filtering by a specific Term.
 */
trait HasTags
{
    /**
     * Polymorphic relationship: this model "has many tags" via morph.
     *
     * @return MorphToMany
     */
    public function tags(): MorphToMany
    {
        $tagClass = config('nestedterms.tag_model', Tag::class);

        return $this->morphToMany(
            $tagClass,
            'model',
            config('nestedterms.model_tag_table', 'model_tag'),
            'model_id',
            'tag_id'
        )->withTimestamps();
    }

    /**
     * Get tags for this model under a specific term (by ID or slug).
     *
     * @param  int|string  $termIdentifier
     * @return MorphToMany
     */
    public function tagsByTerm(int|string $termIdentifier): MorphToMany
    {
        return $this->tags()->whereHas('term', function ($query) use ($termIdentifier) {
            if (is_int($termIdentifier)) {
                $query->where('id', $termIdentifier);
            } else {
                $query->where('slug', $termIdentifier);
            }
        });
    }

    /**
     * Attach one or more tags (Tag instances, IDs, or slugs) to this model.
     *
     * @param  mixed  $tags
     * @return void
     */
    public function attachTags(mixed $tags): void
    {
        $tagIds = $this->normalizeTagIds($tags);
        $this->tags()->syncWithoutDetaching($tagIds);
    }

    /**
     * Detach one or more tags from this model.
     *
     * @param  mixed  $tags
     * @return void
     */
    public function detachTags(mixed $tags): void
    {
        $tagIds = $this->normalizeTagIds($tags);
        $this->tags()->detach($tagIds);
    }

    /**
     * Sync the entire list of tags, optionally detaching those not in $tags.
     *
     * @param  mixed  $tags
     * @param  bool   $detaching
     * @return void
     */
    public function syncTags(mixed $tags, bool $detaching = true): void
    {
        $tagIds = $this->normalizeTagIds($tags);
        $this->tags()->sync($tagIds, $detaching);
    }

    /**
     * Check if this model has a particular tag (by ID, slug, or Tag instance).
     *
     * @param  mixed  $tag
     * @return bool
     */
    public function hasTag(mixed $tag): bool
    {
        if ($tag instanceof Tag) {
            return $this->tags()->where('tags.id', $tag->id)->exists();
        }

        if (is_int($tag)) {
            return $this->tags()->where('tags.id', $tag)->exists();
        }

        // Otherwise treat as slug
        return $this->tags()->where('tags.slug', $tag)->exists();
    }

    /**
     * Convert a list of Tag instances/IDs/slugs into an array of Tag IDs.
     *
     * @param  mixed  $tags
     * @return int[]
     */
    protected function normalizeTagIds(mixed $tags): array
    {
        $tagsArray = is_array($tags) ? $tags : [$tags];
        $ids = [];

        foreach ($tagsArray as $item) {
            if ($item instanceof Tag) {
                $ids[] = $item->id;
            } elseif (is_int($item)) {
                $ids[] = $item;
            } elseif (is_string($item)) {
                // Attempt to find by slug in the related Tag table
                /** @var Tag|null $found */
                $found = $this->tags()->getRelated()->where('slug', $item)->first();
                if ($found) {
                    $ids[] = $found->id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
