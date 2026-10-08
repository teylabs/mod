# Changelog

All notable changes to `mod` will be documented in this file.

## 0.1.0 - YYYY-MM-DD

Initial release.

### Added
- Layouts: name the active layout in `config/mod.php`. Five layouts are built in: `laravel` (the default, placing files exactly like `make:*`), `features`, `slices`, `type-first` and `modules`.
- Define a layout, or extend a built-in one, as one fluent chain: `Mod::layout('name')->root(...)->kind(...)->relation(...)->exclude(...)`. Placeholders such as `{feature}`, `{feature?}` and `{group+}` become the placement values. Kinds can accept nested names (`nested: true`) and be discovered anywhere below their placeholder folders (`discoverAnywhere: true`, with `except:`).
- `mod:*` generator commands for every kind in the active layout. They are thin subclasses of Laravel's `make:*` commands and take `--in` placement or the `Group:Name` shorthand.
- Placement options named from placeholders: each `mod:*` command also takes one option per placeholder its kind uses (`--module=`, `--feature=` and `--slice=`), equivalent to `--in` and the shorthand. Rename one with `Layout::placementOption()`. An option that would shadow one of the command's own is left out and logged; `Preset::placementOptions()` lists the option of each dimension.
- All five built-ins declare the common native kinds and a `tests/` root; `laravel` and `type-first` also declare config files where supported. Components and views remain held to v1; modules no longer declare routes.
- Kinds may declare `fallback:` (or `Kind::fallback()`), used when required placement is omitted. Feature and slice commands have per-feature locations with a global `Console/Commands` fallback.
- Companion artifacts follow the layout's relations: a model's factory, seeder, policy, controller and migration (in every built-in layout), a controller's form requests, a listener's event. A whole generation plan is checked for collisions before anything is written.
- Discovery of the service providers, Artisan commands, event listeners and event subscribers a layout places. Listeners already covered by Laravel's own event discovery, its events cache or a manual registration are never registered twice.
- Migration folders a layout places outside `database/migrations` are added to the migrator, so `php artisan migrate` runs them. Opt out with `'discovery.kinds' => ['migration' => false]`.
- A discovery cache with `mod:discovery-cache` and `mod:discovery-clear`, hooked into `optimize` and `optimize:clear`. A stale cache is ignored with a warning by default (`'on_stale_cache' => 'scan'`), or refused with `'fail'`.
- A host can supply its own candidate files to discovery (`DiscoveryOptions::withCandidates()`), or turn the `mod:*` commands off (`'commands' => false` or `withoutCommands()`) while keeping placement and discovery.
- Protected extension points on the generator commands, including the migration command, for packages that build their own command catalog on top of mod.
- Every exception extends `Tey\Mod\Exceptions\ModException`.
- Support for PHP 8.3+ and Laravel 12 and 13.

### Removed
- The `placementOptionName()` hook of the generator commands. Override `placementOptions()` instead; return `[]` to add no placement options.
