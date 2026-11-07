# Laravel NestedTerms

Editable taxonomy Terms, hierarchical typed Tags and polymorphic Eloquent membership. Model casts and parent checks are reusable; authorization, domain validation and concurrent tree editing stay in the host.

```sh
composer require mrnewport/laravel-nestedterms:^2.0
```

If your Composer registry has not indexed the GitHub tag, add its VCS source and repeat the requirement:

```sh
composer config repositories.laravel-nestedterms vcs https://github.com/MrNewport/laravel-nestedterms
composer require mrnewport/laravel-nestedterms:^2.0
```

Publish `nestedterms-config` before choosing custom tables/models, then run `php artisan migrate`. Configuration changes do not rename or convert saved tables.

Start with the [handbook](docs/HANDBOOK.md) for fresh Laravel 12/13 installation and the complete feature/operational guide. The [feature index](docs/FEATURES.md) links every public API, return contract, failure mode and executable reference. The [recipes](docs/RECIPES.md) show serious standalone and optional cross-package application use.

Laravel's own PHP runtime requirements apply. Package dependency constraints and current test coverage are described in the handbook. Run `composer test` in a development checkout; optional browser/renderer checks are documented with their requirements. Installing this package does not automatically integrate another package or authorize application records.
