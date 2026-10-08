# Changelog

All notable changes to `mod` will be documented in this file.

## [0.1.0] - 2026-10-08

The first release.

### Added
- Six built-in layouts, chosen in `config/mod.php`: `laravel` (the default, placing files like `make:*`), `modules`, `features`, `slices`, `type-first` and `ddd`.
- A `mod:<file type>` command for each file type in the layout, built on Laravel's own `make:*` command with the same arguments and options, for every native class generator including tests. Every hyphenated command also works without the dash (`mod:viewmodel`, `mod:valueobject`).
- Placement by option (`--module=Knowledge`), by `--in=Knowledge`, or by the short form `Knowledge:Document`. Optional (`{feature?}`) and nested (`{domain+}`, `Knowledge.Search`) placeholders, and an `ungrouped:` folder for when the group is left out.
- A group value that differs from an existing folder only by case (`knowledge:Note` when `Knowledge` exists) uses that folder and says so. A near miss (`Knowledg`) asks whether you meant an existing group; without interaction it starts the new group. Every new group folder is announced with the existing ones ("Created new module Knowledg (existing: Agents, Knowledge).").
- File types with a fixed name, such as a slice's `Handler`, need no name argument.
- Running a `mod:*` command the layout doesn't have names the built-in layouts that have it and the `->kind()` call that adds it.
- Related files follow the layout: a model's factory, migration, seeder, policy, controller and form requests, and a listener's event. Every file is checked before any is written.
- The `ddd` layout, with laravel-ddd's folders and `mod:dto`, `mod:value-object`, `mod:view-model` and `mod:action`, plus laravel-ddd's command names as aliases (`mod:data`, `mod:data-transfer-object`, `mod:value`).
- Starter stubs for DTOs (`mod:dto`), view models (`mod:view-model`), value objects (`mod:value-object`) and actions (`mod:action`) in any layout with those file types; `modules` and `ddd` have all four, with `mod:data` and `mod:value` as aliases. spatie/laravel-data, spatie/laravel-view-models and lorisleiva/laravel-actions are used when installed. Otherwise DTOs and view models extend a `DataTransferObject` or `ViewModel` base written into the app once, in `bases_path` (default `app/Support`; `src/Domain/Shared` in `ddd`), and never overwritten. Configure a base of your own per file type with `bases`.
- `mod:bases` writes any missing base classes, for example after copying a module into another app.
- Defining a layout, or extending a built-in one, as one chain: `Mod::layout('name')->root(...)->kind(...)->relation(...)`, with suffixes, fixed names, aliases, output labels and renamed placement options. Relations have `<from>-<to>` ids (`model-factory`, `controller-store-request`) and a `mode:` of `generate`, `reference` or `none`.
- `Mod::hasLayout()`, `Mod::layouts()` and `Mod::current()`, which returns the active layout compiled.
- Published stubs (`stubs/mod.<type>.stub`) and configured base classes for any file type.
- Auto-discovery of the providers, Artisan commands, event listeners and event subscribers a layout places, in each file type's folder or, with `kind(discover: 'anywhere', discoverExcept: [...])`, anywhere below the group. A listener Laravel already registers is never registered twice.
- Migration folders a layout places are added to the migrator, so `php artisan migrate` runs them.
- Factories and policies of models the layout places are found without `newFactory()` or `Gate::policy()`. `mod:model -f` still writes `newFactory()`, so a model works without mod.
- A discovery cache, written by `php artisan optimize` (`mod:cache`) and cleared by `optimize:clear` (`mod:clear`). `mod:cache` explains the files it didn't register, and `-v` lists them.
- APIs for packages built on mod: registering stubs and stub variants (`Mod::stubs()`), starters for any file type (`Tey\Mod\Generation\Starters`), swapping a file type's command (`Mod::generators()`), documented hooks for extending the generator commands, supplying discovery candidates (`Mod::discoverUsing()`), and turning the `mod:*` commands off. Classes outside the documented API are marked `@internal`.
- Exceptions that extend `ModException`, including `UnknownKind`, `InvalidName` and `InvalidLayout`.
- A Laravel Boost guideline and `mod-development` skill, so AI agents follow your layout and use the `mod:*` generators.
- Support for PHP 8.3+ and Laravel 12 and 13.
