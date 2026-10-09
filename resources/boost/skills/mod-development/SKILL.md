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

Use a scaffold when several file types form a reusable recipe. Register it in a provider with `Mod::scaffold()` and include `use Tey\Mod\Scaffolds\Scaffold;` in every scaffold example. Include `use Tey\Mod\Scaffolds\Part;` when typing a part closure. Chain `->makes('model', as: 'model')` and other members; their paths follow the active layout. `Mod::scaffolds()` also accepts invokable recipe classes, and a layout's `->scaffolds()` overrides a global recipe.

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

## Agent foundations: plans and command coverage

Before generating files, run the same command with `--dry-run --json`. It emits only the plan: `command`, `group`, `name`, `files`, `inserts`, `warnings`, and `would_write`. Each file has its alias, file type, path, class or identity, group, and `existing`/`exists` flags. Inserts name their destination, anchor, and rendered stub. Warnings carry a file, line, and message; a missing answer makes `would_write` false and the preview still exits 0. Supply that answer through its flag, then preview again. A plan writes no files and runs no Composer subprocess. Recheck it when the application changes.

Use `--dry-run` alone for the same plan as a text table. A refused collision makes `would_write` false; a scaffold's `--skip-existing` keeps existing files, while `--force` permits overwriting files the command supports. Generated bases are always kept. Read `warnings` and `would_write` before running the command for real.

### Shared options

<!-- mod-shared-options:start -->

These options are covered once for commands that declare them. Check the command's help before using an option:

- `--dry-run` describes files without writing; `--json` formats that preview as JSON. Use both together for agents.
- `--force` allows the command's supported overwrite behaviour; `--skip-existing` keeps existing scaffold members.
- `--in` gives placement in layout order. `--module`, `--domain`, `--feature`, and `--slice` answer built-in group dimensions. Custom group dimensions use the option shown in the inventory and command help.
- `--no-interaction` uses flags and defaults without a terminal. Global console controls are `--help`, `--quiet`, `--verbose`, `--version`, `--ansi`, `--no-ansi`, and `--env`.
- Where the native generator offers them, `--test` also generates a test, and `--pest` or `--phpunit` chooses its style.

<!-- mod-shared-options:end -->

### Registered file type commands

The layout determines which commands exist. Each line names its command-specific options; the native generator defines their defaults. A command unavailable in the current layout explains the available layouts. Dashless aliases generate the same file type.

- `mod:model`: `--all` generates the supported companions; `--factory`, `--migration`, `--seed`, `--policy`, and `--controller` select individual companions. `--resource`, `--api`, and `--requests` shape a companion controller; `--pivot` and `--morph-pivot` select pivot models.
- `mod:controller`: `--resource` or `--api` generates resource actions; `--model` supplies the model, `--parent` supplies a nested resource's parent, and `--requests` generates request classes. `--invokable` generates one action, `--singleton` a singleton resource, `--creatable` its creation actions, and `--type` selects a custom controller template.
- `mod:request` generates a form request; `mod:provider` generates a service provider.
- `mod:command`: `--command` sets the Artisan command name.
- `mod:event` generates an event; `mod:listener` uses `--event` to name its event and `--queued` to queue the listener.
- `mod:job`: `--sync` makes the job synchronous and `--batched` adds batch support.
- `mod:job-middleware` (alias `mod:jobmiddleware`) generates job middleware; `mod:middleware` generates HTTP middleware.
- `mod:mail`: `--markdown` chooses a Markdown mail view and `--view` a regular view.
- `mod:notification`: `--markdown` chooses its Markdown mail template.
- `mod:policy`: `--model` names the model and `--guard` names the authentication guard.
- `mod:observer`: `--model` names the observed model.
- `mod:rule`: `--implicit` makes the validation rule implicit.
- `mod:resource`: `--collection` creates a resource collection; `--json-api` selects JSON:API output when offered by the installed Laravel version.
- `mod:cast`: `--inbound` creates an inbound-only cast.
- `mod:channel` generates a broadcast channel; `mod:scope` generates an Eloquent scope.
- `mod:enum`: `--string` or `--int` selects the backed enum type.
- `mod:exception`: `--render` adds a rendering method and `--report` adds a reporting method.
- `mod:class`: `--invokable` adds an invocation method. `mod:interface` and `mod:trait` create their named language types.
- `mod:factory`: `--model` names the factory's model. `mod:seeder` creates a database seeder.
- `mod:migration`: `--create` names the table being created, `--table` an existing table, and `--fullpath` displays the full output path. The native `--path` and `--realpath` options are refused by mod; use layout placement instead.
- `mod:config` creates a configuration file; `mod:test` uses `--unit` for a unit test.
- `mod:dto` has aliases `mod:data`, `mod:data-transfer-object`, and `mod:datatransferobject`.
- `mod:view-model` has alias `mod:viewmodel`; `mod:value-object` has aliases `mod:value` and `mod:valueobject`.
- `mod:action` generates an action; `mod:handler`, `mod:query`, `mod:validator`, and `mod:message` use the file type declared by their layout.

### Inventory and maintenance commands

- `mod:list --json` reads the machine inventory; `mod:list --type=<file-type>` shows one file type in detail.
- `mod:autoload --namespace=<namespace>` answers an inferred root's namespace. `mod:autoload --no-dump` updates Composer mappings without running Composer.
- `mod:bases --dry-run --json` describes missing bases; existing bases are kept.
- `mod:template --from=<class-or-file> --into=<path>` extracts a generator template. `mod:template --force` overwrites the chosen destination; `mod:template --dry-run --json` previews creation or extraction.
- `mod:cache` builds the discovery cache; `mod:clear` clears it. Use verbosity to inspect rejected files.

### Recipe and template examples

These names describe application-registered examples, rather than extra built-in generators. Register the recipe or generator template first and confirm its command appears in `mod:list --json`.

- `mod:crud Knowledge:Document --dry-run --json` describes the model, migration, factory, store/update requests, resource, policy, and controller in the CRUD recipe.
- `mod:resource-tabs Inventory:Widget --model=Widget --tabs=Overview,Details,Notes` creates a tabs cluster. Its registered parts also expose `mod:resource-tabs --base=<class> --tab=<name>` when those answers are needed.
- `mod:resource-tabs.tab Inventory:Widget History --model=Widget --tabs=Overview,Details,Notes --base=<class> --tab=History --dry-run --json` describes a new tab's files and inserts in its parent cluster. Defaults and parent aliases normally provide the unused answers.
- `mod:tab-page Inventory:Widget --base=<class> --tab=History` uses the standalone page recipe and needs both answers.
- A generator template at `stubs/mod/@module/Tools/[source]/probe.stub` registers `mod:probe Inventory:Widget --source=Drive --dry-run --json`. Its `--source` answer supplies the slot folder and template value.

Every new command and option must be mentioned with its command on the same line in this skill or the Boost guideline. The shared-options block covers only the options listed there. `BoostCoverageTest` checks all built-in layouts and the registered recipe/template examples; A4 pins its missing-option diagnostic.

## Installing Inertia (0.3 · L7)

Before generating module pages, run `mod:install inertia --no-interaction`. Use `mod:install inertia --dry-run --json` to inspect the shared plan, including each file's before and after contents, warnings and `would_write`. Human `--dry-run` previews the same changes. The command detects Vue or React, preserves app page casing and reads the compiled layout's frontend paths. It wires the vendor resolver, `@modules` in Vite and TypeScript, module view refresh paths and Tailwind v3 or v4 scanning. A second run says "Already wired." Blade apps need no install. Mirrored pages already resolve through the app's glob, so their app entry is left alone.

The package exports `resolveModulePage(name, appPages, modulePages)` from `vendor/tey/mod/resources/js/inertia`; pass literal `import.meta.glob` maps from the app. Render a module page as `Inventory::Widget/Index`. Missing pages throw with the file name; module names never fall back to app pages. A custom resolver or unrecognized config is preserved; read the warning and add the printed manual wiring. `mod:list --json` exposes the boolean `wiring.inertia`, `wiring.vite_alias` and `wiring.tailwind` values detected from the current files.

## Module routes

Module routes load only through `Mod::routes(only: [...], except: [...])`. Put the call in `withRouting(then: fn () => Mod::routes())` or inside a Laravel route group to inherit its middleware, URI prefix and name prefix. `config('mod.routes.order')` loads listed modules first, then the rest alphabetically; unknown names warn. Duplicate module calls are refused with both call locations. Cached routes make the call a no-op.

Use `mod:routes Inventory --api --console` for route files: web uses `web`, API uses `api` and `/api`, console loads only in the console. Use `mod:route-registrar Inventory` for a `RegistersRoutes` implementation with static `web()` and `api()` methods. Files load before registrars, and provider-loaded files are skipped. Both commands accept `--force` for replacement and `--dry-run --json` for a plan without writes. Refusals without a terminal name `--force`.

Scaffold inserts use `into: 'routes'` for the module's web file or `into: 'routes.web'` / `into: 'routes.api'` for registrar methods when present. Missing files start with the route anchor. Keep bindings and rate limiters in providers. `mod:list --json` reports the `routes` entries with `group`, `entrypoint`, `kind`, `middleware_group`, `order`, and `loaded_by`; listing never calls a registrar.
