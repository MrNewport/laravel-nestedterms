# NestedTerms feature index

| Surface / signature → return | Contract and errors | Guide |
| --- | --- | --- |
| `Term::tags(): HasMany`, `active()` → Builder, `generateSlug(): void` | Configured model; active flag; fill slug only when empty. Unique name/slug; SoftDeletes. | [Terms](HANDBOOK.md#terms) |
| `Tag::term(): BelongsTo`, `parent(): BelongsTo`, `children(): HasMany`, `models(): MorphToMany`, `active()` → Builder | Configured term and concrete self-relations; typed host reverse relation recommended. | [Hierarchy](HANDBOOK.md#tags-hierarchy-and-slugs) |
| `Tag::generateHierarchySlug(): string` | Term + parent chain + name; cycles/cross-term → InvalidArgumentException; missing parent → ModelNotFoundException. | [Slugs](HANDBOOK.md#tags-hierarchy-and-slugs) |
| `Tag::allDescendants(): Eloquent Collection` | Recursive children, excludes self; no built-in row/depth budget. | [Traversal](HANDBOOK.md#tags-hierarchy-and-slugs) |
| Tag dynamic value casting / allowed_cast_types / custom_type_map | integer/float/double/boolean/string/array/json/object; number synonym; unsupported fallback string. Not validation/exact money. | [Typed values](HANDBOOK.md#typed-values) |
| Tag create/update slug uniqueness | Type/name/parent/term updates regenerate own slug; suffix counters; database constraint final; descendants not automatically rewritten. | [Slugs](HANDBOOK.md#tags-hierarchy-and-slugs) |
| `HasTags::tags(): MorphToMany` | Configured Tag/pivot, timestamps; numeric subject morph column by default. | [Membership](HANDBOOK.md#membership-apis-and-queries) |
| `tagsByTerm(int|string): MorphToMany` | Int ID vs string slug; query can be filtered/paginated. | [Membership](HANDBOOK.md#membership-apis-and-queries) |
| `attachTags(mixed): void`, `detachTags(mixed): void`, `syncTags(mixed, bool $detaching = true): void`, `hasTag(mixed): bool` | Tag/int/full slug or arrays; strings are slugs; unknown slugs ignored; sync affects all terms. | [Membership](HANDBOOK.md#membership-apis-and-queries), [term editor](RECIPES.md#edit-one-term-without-removing-another) |
| Config terms_table/tags_table/model_tag_table/term_model/tag_model | Set before migration; custom classes extend base; config does not move saved data. | [Extension](HANDBOOK.md#configuration-and-extension) |
| NestedTermsServiceProvider / nestedterms-config / migrations | Discovery, three tables, no UI/routes; ownership/structural locks are host policy. | [Install](HANDBOOK.md#fresh-laravel-installation), [upgrades](HANDBOOK.md#data-preserving-upgrades-and-recipes) |
| Optional Fields/Flow/Workflow/Stow composition | Form validation/review/product membership are separate explicit integrations. | [Composition](HANDBOOK.md#optional-composition) |

Executable references: [Tag tests](../tests/Feature/TagTest.php), [Term tests](../tests/Feature/TermTest.php), [membership/custom model tests](../tests/Unit/HasTagsTraitTest.php). Application patterns: [recipes](RECIPES.md).
