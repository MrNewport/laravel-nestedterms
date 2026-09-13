<?php

namespace MrNewport\LaravelNestedTerms\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;
use MrNewport\LaravelNestedTerms\Models\Tag;
use Throwable;

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
     */
    public function attachTags(mixed $tags): void
    {
        $this->mutateTags(fn () => $this->tags()->syncWithoutDetaching($this->normalizeTagIds($tags)));
    }

    /**
     * Detach one or more tags from this model.
     */
    public function detachTags(mixed $tags): void
    {
        $this->mutateTags(fn () => $this->tags()->detach($this->normalizeTagIds($tags)));
    }

    /**
     * Sync the entire list of tags, optionally detaching those not in $tags.
     */
    public function syncTags(mixed $tags, bool $detaching = true): void
    {
        $this->mutateTags(fn () => $this->tags()->sync($this->normalizeTagIds($tags), $detaching));
    }

    /**
     * Check if this model has a particular tag (by ID, slug, or Tag instance).
     */
    public function hasTag(mixed $tag): bool
    {
        if ($tag instanceof Tag) {
            return $this->tags()->where($this->tags()->getRelated()->qualifyColumn('id'), $tag->id)->exists();
        }

        if (is_int($tag)) {
            return $this->tags()->where($this->tags()->getRelated()->qualifyColumn('id'), $tag)->exists();
        }

        // Otherwise treat as slug
        return $this->tags()->where($this->tags()->getRelated()->qualifyColumn('slug'), $tag)->exists();
    }

    /**
     * Convert a list of Tag instances/IDs/slugs into an array of Tag IDs.
     *
     * @return int[]
     */
    protected function normalizeTagIds(mixed $tags): array
    {
        $related = $this->tags()->getRelated();
        $relatedClass = $related::class;
        $identifiers = [];
        $requestedIds = [];
        $requestedSlugs = [];
        $limit = (int) config('nestedterms.limits.max_attachment_inputs', 1000);
        if ($limit < 1) {
            throw new InvalidArgumentException('The attachment input limit must be positive.');
        }
        foreach (is_iterable($tags) ? $tags : [$tags] as $item) {
            if (count($identifiers) >= $limit) {
                throw new InvalidArgumentException('The attachment input limit was exceeded.');
            }
            if ($item instanceof Tag) {
                if (! $item instanceof $relatedClass || ! $item->exists || $item->getTable() !== $related->getTable()
                    || $item->getConnection()->getName() !== $related->getConnection()->getName()) {
                    throw new InvalidArgumentException('Every tag must resolve through the configured tag model and scope.');
                }
                $item = $item->getKey();
            }
            if (is_int($item) && $item > 0) {
                $requestedIds[] = $item;
                $identifiers[] = ['id', $item];
            } elseif (is_string($item) && $item !== '') {
                $requestedSlugs[] = $item;
                $identifiers[] = ['slug', $item];
            } else {
                throw new InvalidArgumentException('Tags must be persisted tag models, positive integer IDs, or nonempty slugs.');
            }
        }
        if ($identifiers === []) {
            return [];
        }
        $resultLimit = count(array_unique($requestedIds)) + count(array_unique($requestedSlugs));
        $found = $related->newQuery()->where(fn ($query) => $query->whereIn($related->qualifyColumn('id'), array_unique($requestedIds))
            ->orWhereIn($related->qualifyColumn('slug'), array_unique($requestedSlugs)))->limit($resultLimit + 1)->lockForUpdate()->get();
        if ($found->count() > $resultLimit) {
            throw new InvalidArgumentException('Tag slugs must resolve unambiguously through the configured tag model and scope.');
        }
        $byId = $found->keyBy('id');
        $bySlug = $found->groupBy('slug');
        $ids = [];
        foreach ($identifiers as [$kind, $identifier]) {
            $matches = $kind === 'id' ? $byId->get($identifier) : ($bySlug->get($identifier)?->count() === 1 ? $bySlug->get($identifier)->first() : null);
            if ($matches === null) {
                throw new InvalidArgumentException('Every tag must resolve unambiguously through the configured tag model and scope.');
            }
            $ids[] = $matches->getKey();
        }

        return array_values(array_unique($ids));
    }

    private function mutateTags(callable $operation): void
    {
        if (! $this->exists) {
            throw new InvalidArgumentException('Save the model before changing its tag attachments.');
        }
        $connection = $this->getConnection();
        if ($connection->getName() !== $this->tags()->getRelated()->getConnection()->getName()) {
            throw new InvalidArgumentException('The model and its tags must use the same database connection.');
        }
        $baseline = $connection->transactionLevel();
        try {
            $connection->beginTransaction();
            $operation();
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack($baseline);
            throw $exception;
        }
    }
}
