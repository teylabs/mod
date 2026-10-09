# Changelog

All notable changes to `mod` will be documented in this file.

## [0.3.1] - Unreleased

### Fixed

- Keep the `ddd` preset free of frontend folders; frontend generation explains how to opt in with `->frontend()`.
- Keep `ddd` views unqualified and markdown mail and notification views in Laravel's `resources/views` folder.
- Restore `ddd` controllers, requests and middleware to `Controllers`, `Requests` and `Middleware` under `app/Modules/<Domain>`.
- Remove the `ddd` routes mount; extended layouts can opt in with `->mounts('routes', null, ...)`.

## [0.3.0] - 2026-10-09

### Added
- Frontend files from templates: a `.stub` whose name ends in another extension becomes a generator (`card.blade.php.stub` → `mod:card`, `filter.vue.stub` → `mod:filter`). File names follow the stack's casing.
- `mod:page` writes a minimal Inertia page for the app's stack (Vue or React, TypeScript when `tsconfig.json` exists) into the module, following the app's `pages/` or `Pages/` casing.
- `mod:install inertia` previews and wires Vue and React apps for module pages: the page resolver shipped in `vendor/`, the `@modules` Vite and TypeScript aliases, and Tailwind sources. Rerunning reports "Already wired."; custom setups get manual instructions.
- Placeholders that link PHP and frontend files: `{{ page }}` (`Inventory::Widget/Index`), `{{ view }}`, `{{ import }}` and `{{ tag }}`. Only known names are replaced, `@{{ }}` escapes, and an ambiguous bare name in Vue or Blade warns with its file and line.
- Frontend files as scaffold members, with stack-specific variants, file questions and anchored inserts.
- `mod:template --from` copies a Vue or Blade file byte for byte and reports which names to turn into placeholders.
- `->frontend(pages:, components:, css:, views:)` relocates a layout's frontend folders. Folders that differ only by case are refused.
- Module view namespaces (`view('inventory::widgets.show')`) and anonymous components (`<x-inventory::widget-card />`), with `mod:view` and `mod:component`. `mod:mail --markdown` and `mod:notification --markdown` write their views in the module.
- `Mod::routes()` loads each module's `routes/web.php`, `api.php` and `console.php` with Laravel's groups, from `bootstrap/app.php` or inside your own route group. `only:`, `except:` and `config('mod.routes.order')` control which modules load and when. Works with `route:cache`.
- Optional `RegistersRoutes` classes for routes in PHP, and `mod:routes` and `mod:route-registrar` to start either. Scaffolds can insert routes with `into: 'routes'`.
- Scaffold members can be `ungrouped: true`, ask their own `group:`, or use `existing: 'keep'` to write a shared file once.
- Modules can carry their own templates (`<module>/stubs/mod/`) and scaffolds (`<module>/Scaffolds/` or `Mod::scaffolds()`). The module wins over the app, and the app over packages.
- `--dry-run --json` on every command that writes files prints a plan of what it would write, without writing or prompting.
- `mod:list --json` adds stack, frontend, views, routes and wiring sections, and where each template and scaffold comes from.
- With Laravel Boost installed, read-only `mod-inventory` and `mod-plan` MCP tools let agents read the inventory and preview generators.

### Changed
- HTTP classes (controllers, requests, middleware, resources) now live under Http/
- Class discovery skips a module's `resources/` and `routes/` folders.

## [0.2.0] - 2026-10-09

### Added
- Generator templates in `stubs/mod/`: anchors, value slots, name and sibling placeholders, package template folders and PHP refinements. App templates take precedence over package templates.
- `mod:template` creates a template from a class, interface, trait, enum, starter or usable file type, or extracts an existing PHP class with `--from` and `--into` without loading it.
- `mod:list` shows the active layout, groups, file types, templates, scaffolds and discovery. `-v` lists discovered classes, `--type` filters a file type and `--json` supplies a machine-readable inventory.
- `mod:autoload` registers missing Composer PSR-4 mappings for layout roots and moved group folders, with `--dry-run`, `--no-dump` and `--namespace`.
- `->path()`, `->extends()` and `->allowsNesting()` for group folders, layout inheritance and nesting.
- Scaffolds generate several file types together: sibling aliases, named stub variants, `include()`, invokable recipe classes, packages, layout overrides and collision choices.
- Scaffold trees with `asks()`, `each()`, `part()`, `inserts()` and `mod:<root>.<part>` commands to grow a cluster. Anchored inserts can register routes; recursive trees remain finite in the registry and cache.
- Laravel Prompts for outcomes that one answer resolves, with actionable options for non-interactive runs.

### Changed
- Layout API names are renamed outright: `root()` → `mounts()`, `kind()` → `generates()`, `relation()` → `relates()`, `exclude()` → `excludes()`, and `typeFolders()` → `path()`.
- `placementOption()` is removed. The token in `path()` names the placement option, anchor and placeholder.
- Public `Kind` → `FileType`, `UnknownKind` → `UnknownFileType`, `inKindRoot()` → `inFileTypeRoot()`, and `discovery.kinds` → `discovery.file_types`. Existing layout customizations must update these names.
- The discovery cache stores template inventory and finite scaffold tree metadata, and checks template fingerprints for stale caches.

### Fixed
- Template body group tokens and name forms follow renamed layout tokens. Explicit `path()` tokens take precedence over names derived from inherited layouts.
- `mod:list` includes factory and policy counts and targets, and identifies package templates overridden by the application.
- Package template folders accept relative paths containing `..`. Conflicting template commands explain how to override or rename the templates.
- `mod:autoload` distinguishes configured mappings from loaded classes and reports Composer failures while retaining the written entries.
- Scaffold replays recognize existing migrations by name across timestamps, so refusal, `--skip-existing` and `--force` use the original migration file.
- Generation avoids false autoload warnings for Composer-covered folders and repeated new-group notices for companion files.
- House factory stubs retain their `$model` value. Migrations can be scaffold members.

## [0.1.1] - 2026-10-08

### Fixed
- Subclasses of the `mod:*` commands can override their methods with Laravel's signatures again. 0.1.0 declared `getStub(): string`, and `configure(): void` where Symfony Console 7 leaves `configure()` untyped, so an override without the return type was a fatal error.
- "Created new <group>" is printed only when the file is written, not when the command then refuses (an unknown policy guard, an invalid observer model, an unknown controller type).
- A nested group's own folders (`Billing/Controllers`) are no longer listed as existing groups when the root also places classes right in the group.

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
