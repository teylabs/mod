---
name: mod-development
description: "Generate and place files with tey/mod: mod:* Artisan commands, the application's layout (built-in or defined with Mod::layout()), starter stubs and base classes, and discovery of providers, commands, listeners and migrations. Use when the application requires tey/mod and you create classes, change its layout, stubs or config/mod.php, or debug discovery. Not for applications without tey/mod, or for general architecture advice."
license: MIT
metadata:
  author: Jasper Tey / Tey Labs
---

# Mod development

Work within the application's layout and existing conventions. Mod places files and discovers classes; it does not require any business-logic pattern.

## Establish the layout

- Run `php artisan mod:list --json` first. Read the active layout, file types, templates, scaffolds and discovery before choosing a command.
- `config/mod.php` names the layout with `'layout'`. Without that file, the package default is `laravel`, which places files exactly like `make:*`.
- The built-in layouts are `laravel`, `modules` (`app/Modules/<Module>`), `features` (`app/Features/<Feature>`), `slices` (`app/<Feature>/<Slice>`), `type-first` (`app/Models/<Feature?>`) and `ddd` (`src/Domain/<Domain>`, with controllers, requests and middleware in `app/Modules/<Domain>`).
- Search the service providers for `Mod::layout(`. A call with a built-in name customizes that layout; another name defines a new one. `->generates('validator', in: 'Modules/{module}/Validators')` adds a file type, and each `{placeholder}` in a folder becomes an option of that type's command (`--module=`).
- `Mod::layout('areas')->extends('modules')` copies a parent layout. `extends()` must come first; it copies the parent at that moment. A name-derived token follows the child (`area`); a token declared with `path()` is inherited.
- `->path('src/Areas/{area}')` names and moves group folders, relative to the project root. Absolute paths work too. `->allowsNesting()` permits nested groups; name the dimension on layouts with several groups.
- After choosing `ddd` or a custom layout with roots outside `app/`, run `php artisan mod:autoload`. It adds each missing PSR-4 entry in `composer.json` (for `ddd`: `"Domain\\": "src/Domain/"`). It then runs Composer. Use `--dry-run` to preview, or `--no-dump` when a script runs Composer itself. Resolve conflicting mappings in `composer.json` before running the command again. Generation warns and still writes files when a root is not autoloaded yet.
- `php artisan list mod` lists the generators this layout has. `php artisan help mod:<type>` shows a generator's placement options and Laravel's own options.
- The installed package's `vendor/tey/mod/docs/layouts.md` lists every built-in layout's folders.

## Generate files

Always pass `--no-interaction`, the class name and the placement. Answer required questions with their options; use `php artisan help mod:<type>` to find them. File types with a fixed name, such as a slice's handler, need only the placement:

```bash
php artisan mod:model Knowledge:Document -mf --no-interaction
php artisan mod:model Document --in=Knowledge --no-interaction
php artisan mod:model Document --module=Knowledge --no-interaction
php artisan mod:handler --in=Knowledge/IndexDocument --no-interaction # slices: feature, then slice; writes Handler.php
php artisan mod:model Report --domain=Knowledge.Search --no-interaction # ddd: nested domain
```

- The first three commands are equivalent. Use one form per command.
- Match the existing group folder's name. A case-only difference uses the existing folder (`Using existing module Knowledge (you typed knowledge).`). A near miss such as `Knowledg` prompts in a terminal; pass `--no-interaction` so the command never waits for input. `Created new module <Name>` in the output means a new group folder was made; if that wasn't intended, it was a typo, so delete the folder and rerun.
- Hyphenated commands also work without the dash (`mod:viewmodel`). A command the layout lacks exits 1 and names the layouts that have it; don't switch layouts to get it.
- Without placement, a command exits with an error naming the options it accepts. The exceptions are `mod:command` in `features` and `slices`, which then writes to `app/Console/Commands`, and `type-first`, whose feature folder is optional.
- Each `mod:*` command is Laravel's `make:*` command underneath, so Laravel's options pass through: `-m`, `-f`, `--policy`, `--controller`, `--resource`, `--requests` (requests need a resource controller), `--all`, `--event=`, `--model=`. Related files land in the same module.
- When the file already exists, the command prints `<path> already exists.`, writes nothing and exits 0, as `make:*` does. Read the output instead of trusting the exit code.
- `--force` overwrites the primary file. When a related file also exists, the command prints `Nothing was written.` and exits 1. Check existing files before using `--force`.
- `make:*` still writes to Laravel's default folders. Use `mod:*` for files that belong in a module.

Inspect the generated namespace, imports and related files, then run the tests relevant to the change.

## Generator templates

When a class shape repeats, use `mod:template <type> <name>` with both arguments, or extract it with `mod:template --from=<Class> --into=<path> --no-interaction`. Pass `--no-interaction` for creation too. With one argument, it is the name of a class template, not the starting type.

Generator templates live in `stubs/mod/`. An anchor such as `@module` follows the layout's group folder; `[source]` creates a required `--source` option. Quote shell paths containing a `[slot]`. Edit the template once, then use its `mod:<name>` command with explicit placement and slot options. Extraction changes namespace and declared class-name tokens; inspect comments, imports and the remaining application-specific code.

Use `->generates()` to refine a template or declare a file type in PHP; `->mounts()`, `->relates()` and `->excludes()` are the Layout API verbs. Use `->extends()` only when copying another layout.

## Scaffolds

Use a scaffold when several file types form a reusable recipe. Register it in a provider with `Mod::scaffold()` and import `Tey\Mod\Scaffolds\Scaffold`. Chain `->makes('model', as: 'model')` and other members; their paths follow the active layout. `Mod::scaffolds()` also accepts invokable recipe classes, and a layout's `->scaffolds()` overrides a global recipe.

- `as:` names sibling aliases: `{{ model }}` is the short class name, `{{ model.fqcn }}` is the full name. Name forms chain left to right (`{{ name.plural.kebab }}`).
- `stub: 'crud'` selects `stubs/mod.<type>.crud.stub`. Create that variant before non-interactive generation; variant publication happens only after the plan is accepted.
- `->include('recipe')` reuses a recipe. A later member with the same alias replaces the included member.
- Read the complete file and insert plan before writing. Pass `--no-interaction` and required question options. Choose `--skip-existing` to keep existing files or `--force` to overwrite them.
- `->asks()` declares questions and options; `->each()` repeats a `->part()`. Parts can use another scaffold and pass values with `with:`. Use `configure:` for a closure after named arguments.
- `->inserts()` on a part writes an insert stub before a retained `mod:<anchor>` marker in a file owned by that parent. It uses fully qualified names and does not edit imports. A child command `mod:<root>.<part>` can grow an existing cluster.
- Members support PHP class file types and migrations, including template file types. Pages, Blade, Vue and CSS remain manual. Only a routes file can be started by an insert; its module provider must load it.

Before writing a reusable recipe, record required packages, import aliases and configured model bases. Keep authorization, validation and frontend work explicit in the application's task. App recipes override package recipes; check `mod:list --json` for effective provenance and tree children.

## Self-contained modules

In `modules`, everything a module needs (models, migrations, factories, policies, controllers, requests, actions, DTOs, events, listeners, jobs) is under `app/Modules/<Module>`. Route files aren't discovered: load a module's routes from its own provider (`mod:provider Knowledge:Knowledge`, then `$this->loadRoutesFrom(__DIR__.'/../routes/web.php')` in `boot()`), which discovery registers. A module copied into another application that has mod installed with the same layout brings its routes, migrations, listeners, factories and policies with it. Its DTOs and view models extend base classes in `app/Support`; run `php artisan mod:bases` in the new application to write any that are missing.

## Stubs and base classes

- `mod:dto` (alias `mod:data`), `mod:view-model`, `mod:value-object` (alias `mod:value`) and `mod:action` start from starter stubs in any layout that has those types (`modules` and `ddd` have all four).
- When `spatie/laravel-data`, `spatie/laravel-view-models` or `lorisleiva/laravel-actions` is installed, the matching command uses it. Don't install them only because an example mentions them.
- Otherwise DTOs and view models extend a base class that mod writes into the application once: `App\Support\Data\DataTransferObject` and `App\Support\ViewModels\ViewModel` under `bases_path` (default `app/Support`); in `ddd`, `src/Domain/Shared`. Mod never overwrites an existing base. `php artisan mod:bases` writes missing bases and changes nothing on a second run.
- `config/mod.php` `'bases' => ['dto' => App\Support\Data::class]` makes a type extend the application's own class.
- An application stub wins: `stubs/mod.<type>.stub` (for example `stubs/mod.dto.stub`) and `stubs/mod.base.<name>.stub` for a base. Look for published stubs before assuming the default output.
- A file type with no Laravel generator and no stub starts as an empty class.

## Discovery

- Providers, Artisan commands, event listeners and event subscribers in the layout's folders are registered automatically. A listener Laravel already registers is never registered twice.
- Migration folders the layout places, such as `app/Modules/<Module>/Database/Migrations`, are added to the migrator. A model's factory and policy are found through the layout.
- Check registration with `php artisan event:list`, `php artisan list` and `php artisan migrate:status`.
- `php artisan optimize` writes the discovery cache (`mod:cache`), and `optimize:clear` removes it (`mod:clear`). The cache doesn't pick up new classes: after adding a provider, command or listener while it exists, run `php artisan mod:clear`.
- `mod:cache` reports files it found but didn't register as "rejected", with a reason; `mod:cache -v` lists them. Files "placed by no file type" need nothing.
- `config/mod.php` `discovery.enabled`, `discovery.file_types`, `discovery.factories` and `discovery.policies` control what is discovered.

## What to avoid

- Don't move existing code to match an example or a different layout.
- Don't edit `vendor/tey/mod`. Change the layout with `Mod::layout()` in a service provider, the output with stubs, and the settings in `config/mod.php`.
- Don't use `make:*` for a file that belongs in a module.
- Don't invent `mod:*` commands or options. Check `php artisan list mod` and `php artisan help mod:<type>`. Add a missing file type with a generator template or `->generates()` when it is within the requested scope.

The installed package's source and README describe its exact behaviour. Application instructions and the user's chosen scope take precedence over these examples.
