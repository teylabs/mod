# Changelog

All notable changes to `mod` will be documented in this file.

## [0.1.0] - YYYY-MM-DD

The first release.

### Added
- Six built-in layouts, chosen in `config/mod.php`: `laravel` (the default, placing files like `make:*`), `modules`, `features`, `slices`, `type-first` and `ddd`.
- A `mod:*` command for each file type in the layout, built on Laravel's own `make:*` command with the same arguments and options, for every native class generator including tests.
- Placement by option (`--module=Billing`), by `--in=Billing`, or by the short form `Billing:Invoice`. Optional (`{feature?}`) and nested (`{domain+}`, `Reporting.Internal`) placeholders, and a fallback folder when the group is left out.
- Related files follow the layout: a model's factory, migration, seeder, policy, controller and form requests, and a listener's event. Every file is checked before any is written.
- The `ddd` layout, with laravel-ddd's folders and `mod:dto`, `mod:value`, `mod:view-model` and `mod:action`, plus laravel-ddd's command names as aliases.
- Starter stubs for DTOs, value objects, view models and actions. spatie/laravel-data, spatie/laravel-view-models and lorisleiva/laravel-actions are used when installed; otherwise a `DataTransferObject` or `ViewModel` base is written into the app once and never overwritten.
- Defining a layout, or extending a built-in one, as one chain: `Mod::layout('name')->root(...)->kind(...)->relation(...)`, with suffixes, fixed names, aliases, output labels and renamed placement options.
- Published stubs (`stubs/mod.<type>.stub`) and configured base classes for any file type.
- Auto-discovery of the providers, Artisan commands, event listeners and event subscribers a layout places. A listener Laravel already registers is never registered twice.
- Migration folders a layout places are added to the migrator, so `php artisan migrate` runs them.
- Factories and policies of models the layout places are found without `newFactory()` or `Gate::policy()`.
- A discovery cache, written by `php artisan optimize` (`mod:discovery-cache`) and cleared by `optimize:clear` (`mod:discovery-clear`).
- APIs for packages built on mod: registering stubs and stub variants (`Mod::stubs()`), swapping a file type's command (`Mod::generators()`), extending the generator commands, supplying discovery candidates, and turning the `mod:*` commands off.
- A Laravel Boost guideline and `mod-development` skill, so AI agents follow your layout and use the `mod:*` generators.
- Support for PHP 8.3+ and Laravel 12 and 13.
