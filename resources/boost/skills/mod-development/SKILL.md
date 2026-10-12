---
name: mod-development
description: "Generate and place files with tey/mod: mod:* Artisan commands, the application's layout (built-in or defined with Mod::layout()), starter stubs and base classes, and discovery of providers, commands, listeners and migrations. Use when the application requires tey/mod and you create classes, change its layout, stubs or config/mod.php, or debug discovery. Not for applications without tey/mod, or for general architecture advice."
license: MIT
metadata:
  author: Jasper Tey / Tey Labs
---

# Mod development

Work within the application's layout and existing conventions. Mod places files and discovers classes; it does not require any business-logic pattern. This skill holds the detailed reference; the core guideline only directs agents to inventory and preview their work. Before placing a file, check the configured layout, its service-provider customizations and Composer PSR-4 mappings. Prefer the matching `mod:*` generator and explicit placement over `make:*`.

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

## HTTP and frontend paths

Module HTTP file types use Http/Controllers, Http/Requests, Http/Middleware and Http/Resources. DDD keeps domain API resources in src/Domain/<Domain>/Resources and application HTTP classes in app/Modules/<Domain>/Controllers, Requests and Middleware. The ddd preset declares no frontend or routes roots. Module resources/ and routes/ roots contain plain files and are excluded from class discovery.

Use ->frontend(pages: ..., components: ..., css: ..., views: ..., pageName: ...) to customize frontend paths. Omitted arguments retain defaults; ->extends() copies the configuration. Built-in page folders follow the app’s pages/Pages casing, while explicit overrides retain their spelling. Inspect frontend paths and page_name in mod:list --json rather than assuming an Inertia stack. Compilation checks case-only folder collisions; move frontend paths under ui/ when a custom API Resources/ folder would clash with resources/.

## Agent foundations: plans and command coverage

Before generating files, run the same command with `--dry-run --json`. It emits only the plan: `command`, `group`, `name`, `files`, `inserts`, `warnings`, and `would_write`. Each file has its alias, file type, path, class or identity, group, and `existing`/`exists` flags. Inserts name their destination, anchor, and rendered stub. Warnings carry a file, line, and message; a missing answer makes `would_write` false and the preview still exits 0. Supply that answer through its flag, then preview again. A plan writes no files and runs no Composer subprocess. Recheck it when the application changes.

Use `--dry-run` alone for the same plan as a text table. A refused collision makes `would_write` false; a scaffold's `--skip-existing` keeps existing files, while `--force` permits overwriting files the command supports. Generated bases are always kept. Read `warnings` and `would_write` before running the command for real. Warnings retain `message` and add stable `code` plus object `data`; `missing_placement` supplies the dimension, accepted options and available choices. Tolerate additional codes and fields.

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
- Match the existing group folder's name. A case-only difference uses the existing folder (`Using existing module Knowledge (you typed knowledge).`). A near miss such as `Knowledg` prompts in a terminal; pass `--no-interaction` so the command never waits for input. `Created new module <Name>` in the output means a new group folder was made; check the selected placement before generating more files.
- Hyphenated commands also work without the dash (`mod:viewmodel`). A command the layout lacks exits 1 and names the layouts that have it; don't switch layouts to get it.
- Without placement, a command exits with an error naming the options it accepts. The exceptions are `mod:command` in `features` and `slices`, which then writes to `app/Console/Commands`, and `type-first`, whose feature folder is optional.
- Class and Blade adapters use Laravel's `make:*` commands underneath, so Laravel's options pass through: `-m`, `-f`, `--policy`, `--controller`, `--resource`, `--requests` (requests need a resource controller), `--all`, `--event=`, `--model=`. Related files land in the same module.
- When the file already exists, the command prints `<path> already exists.`, writes nothing and exits 0, as `make:*` does. Read the output instead of trusting the exit code.
- `--force` overwrites the primary file. When a related file also exists, the command prints `Nothing was written.` and exits 1. Check existing files before using `--force`.
- `make:*` still writes to Laravel's default folders. Use `mod:*` for files that belong in a module.

Inspect the generated namespace, imports and related files, then run the tests relevant to the change.

## Generator templates

When a class shape repeats, use `mod:template <type> <name>` with both arguments, or extract it with `mod:template --from=<Class> --into=<path> --no-interaction`. Pass `--no-interaction` for creation too. With one argument, it is the name of a class template, not the starting type.

Generator templates live in `stubs/mod/`. An anchor such as `@module` follows the layout's group folder; `[source]` creates a required `--source` option. Quote shell paths containing a `[slot]`. Edit the template once, then use its `mod:<name>` command with explicit placement and slot options. Extraction changes namespace and declared class-name tokens; inspect comments, imports and the remaining application-specific code.

Use `->generates()` to refine a template or declare a file type in PHP; `->mounts()`, `->relates()` and `->excludes()` are the Layout API verbs. Use `->extends()` only when copying another layout.

## Frontend members

Packages read `Mod::stack()` (`inertia`, `typescript`, `pagesPath`, `livewire`, `toArray`). Declare package pages with `Mod::pages(namespace, absoluteDirectory)` and explicit import paths/sources through `Mod::wiring()->alias(name, directory)->source(directory)`. Its `plan()` previews and explicitly `apply()`s the same wiring; providers never write app files. Mounted roots use root-relative `Root::frontend()` with explicit `alias`, `pageName` and `viewNamespace`; component-only roots need no pages or views. Inspect `frontend_owners`, `package_pages` and `frontend_wiring` before installation.

Declare static globs and executable templates separately with `Layout::assets(files:, entries:)` or root-relative `Root::assets()`. `mod:install assets --dry-run --json` previews without Inertia; `mod:install assets --no-interaction` explicitly synchronises the owned `resources/mod-assets.json` and preserves central inputs. Static automatic wiring requires Laravel Vite plugin 3. Use imports, `Vite::asset()` and `@vite` normally; independent styles are loaded only when the app selects them. Sync when executable entry owners change, not when adding static files under an existing glob.

- `mod:page <group>:<nested-name> --dry-run --json` previews the detected stack's page path and identity; `mod:page <group>:<nested-name> --no-interaction` generates it. Use `--force` only when overwriting the existing page is intended.
- Plain generator templates use their filename's first dot as the command/extension boundary. `{{ alias.name }}`, `.import`, `.tag`, `.path`, and the bare component alias come from the resolved layout. Keep Vue/Blade expressions unknown to mod, or escape them with `@{{ … }}`. Use explicit forms for known names to avoid informational bare-placeholder warnings.
- Page variants include the extension, such as `stubs/mod.page.crud-index.tsx.stub`. Packages register variants with `Mod::stubs()->forFileType('page.crud-index.tsx', Stub::file(...))`. The app's stub wins, then the matching registered extension key, then the generic `page.crud-index` key. Supply each supported stack's variant; another extension's key is never chosen. Preview complete scaffold plans before writing; missing variants and anchors refuse before generated files change.
- `mod:template --from=<plain-file> --into=<template-path> --dry-run --json` reports `mentions[]`. The real run copies the bytes unchanged. Generalize the reported mentions manually with known placeholders.

Use `@modules/...` imports for files under the layout group root and `@/...` for files under `resources/js`. Never cross an alias root with `../`. A part keeps the cluster name in `{name}`; use its question placeholder (such as `{widget}`) for the part value.

## Installing Inertia

Before generating module pages, run `mod:install inertia --no-interaction`. Use `mod:install inertia --dry-run --json` to inspect the shared plan, including each file's before and after contents, warnings and `would_write`. Human `--dry-run` previews the same changes. The command detects Vue, React or Svelte, preserves app page casing and reads the compiled layout's frontend paths. It wires the vendor resolver, `@modules` in Vite and TypeScript, module view refresh paths and Tailwind v3 or v4 scanning. A second run says "Already wired." Blade apps need no install. Mirrored pages already resolve through the app's glob, so their app entry is left alone.

The package exports `resolveModulePage(name, appPages, modulePages)` from `vendor/tey/mod/resources/js/inertia`; pass literal `import.meta.glob` maps from the app. Render a module page as `Inventory::Widget/Index`. Missing pages throw with the file name; module names never fall back to app pages. A custom resolver or unrecognized config is preserved; read the warning and add the printed manual wiring. `mod:list --json` exposes the boolean `wiring.inertia`, `wiring.vite_alias` and `wiring.tailwind` values detected from the current files.

## Module views and Blade components

- `mod:view Inventory:widgets.show` runs Laravel's view generator in the group's views folder. Options: `mod:view --extension=blade.php --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use the result with `view('inventory::widgets.show')`.
- `mod:component Inventory:StockBadge --view` creates an anonymous Blade component. `mod:component Inventory:WidgetTable` creates a class in `View/Components` and its view. Options: `mod:component --view --inline --path --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use `<x-inventory::stock-badge />`; Vue and React components come from generator templates.
- `mod:mail Inventory:WidgetRestocked --markdown=mail.widget-restocked` writes both the mailable and its view, using `inventory::mail.widget-restocked`. `mod:mail --view=mail.widget-restocked` writes a plain Blade view. `mod:notification --markdown=mail.widget-restocked` qualifies its Markdown view the same way. Their `--dry-run --json` plans include both files and the view identity; `--force` replaces planned views.
- View namespaces register after providers boot, even without route or class discovery. Groups without views are omitted. Reserved namespaces (`mail`, `notifications`, `pagination`) and existing namespace clashes are skipped with a warning. Module views participate in `view:cache`.
- DDD declares no view folders or namespaces; markdown mail and notification views use Laravel’s resources/views and unqualified names. Opt in with ->frontend() on an extended layout. Type-first grouped views use kebab subfolders such as `resources/views/inventory`; ungrouped views stay in `resources/views` with no namespace. `mod:list --json` adds a `views` section with group, namespace, path and component tags. View identities expose name, tag and path for plain-file members.

## Module routes

Module routes load only through `Mod::routes(only: [...], except: [...])`. Put the call in `withRouting(then: fn () => Mod::routes())` or inside a Laravel route group to inherit its middleware, URI prefix and name prefix. `config('mod.routes.order')` loads listed modules first, then the rest alphabetically; unknown names warn. Duplicate module calls are refused with both call locations. Cached routes make the call a no-op.

Use `mod:routes Inventory --api --console` for route files: web uses `web`, API uses `api` and `/api`, console loads only in the console. Use `mod:route-registrar Inventory` for a `RegistersRoutes` implementation with static `web()` and `api()` methods. Files load before registrars, and provider-loaded files are skipped. Both commands accept `--force` for replacement and `--dry-run --json` for a plan without writes. Refusals without a terminal name `--force`.

Scaffold inserts use `into: 'routes'` for the module's web file or `into: 'routes.web'` / `into: 'routes.api'` for registrar methods when present. Missing files start with the route anchor. Keep bindings and rate limiters in providers. `mod:list --json` reports the `routes` entries with `group`, `entrypoint`, `kind`, `middleware_group`, `order`, and `loaded_by`; listing never calls a registrar.

## Scaffolds

Package scaffolds require `Mod::vendor('acme')->scaffold('pages', $recipe)` or `Mod::vendor('acme')->scaffolds([...])`. Commands use `mod:acme/pages`, with dot parts beneath that id. `command: 'kit:pages'` selects one canonical command. Use full vendor ids in includes, layout overrides and `mod:rename --scaffold=acme/pages`. App definitions win; a conflicting vendor prefix asks to continue interactively and warns on stderr without interaction. Two package providers require a run selection or a provider-class pin in `config/mod.php` under `scaffolds.prefer['acme/pages']`; non-interactive unresolved conflicts refuse with `vendor_scaffold_conflict`, including exit 1 JSON previews.

Use a scaffold when several file types form a reusable recipe. Register it in a provider with `Mod::scaffold()` and include `use Tey\Mod\Scaffolds\Scaffold;` in every scaffold example. Include `use Tey\Mod\Scaffolds\Part;` when typing a part closure. Chain `->makes('model', as: 'model')` and other members; their paths follow the active layout. `Mod::scaffolds()` also accepts invokable recipe classes, and a layout's `->scaffolds()` overrides a global recipe.

- `as:` names sibling aliases: `{{ model }}` is the short class name, `{{ model.fqcn }}` is the full name. Name forms chain left to right (`{{ name.plural.kebab }}`).
- `stub: 'crud'` selects `stubs/mod.<type>.crud.stub`. Create that variant before non-interactive generation; variant publication happens only after the plan is accepted.
- `->include('recipe')` reuses a recipe. A later member with the same alias replaces the included member.
- Read the complete file and insert plan before writing. Pass `--no-interaction` and required question options. Choose `--skip-existing` to keep existing files or `--force` to overwrite them.
- `->named('Acme')` on a root scaffold declares a default name: the command's `name` argument becomes optional (shown in `--help`), an explicit name wins, and `--dry-run --json` reports the resolved name. Default names belong to root scaffolds; calling `named()` on a part is refused because it takes its root scaffold's name. Includes do not copy default names.
- `->asks()` declares questions and options; `->each()` repeats a `->part()`. Parts can use another scaffold and pass values with `with:`. Use `configure:` for a closure after named arguments.
- `->asks('columns', type: 'records', shape: ['key' => 'string', 'label' => 'string', 'sortable' => 'bool'], default: [...])` asks for a list of objects. Shape types are `string`, `bool` and `int`. Interactive runs prompt per record; non-interactive runs pass JSON, `--columns='[{"key":"name","label":"Name","sortable":true}]'`, or use the default. Records are validated against the shape before anything is planned; `--columns='[]'` is an empty list.
- Template blocks: `{{ #columns }}` ... `{{ /columns }}` on their own lines repeat the lines between them for each record (fields in scope, outer placeholders reachable), or render once when a boolean is true. `{{ ^name }}` ... `{{ /name }}` renders when it is false or the list is empty. Tag lines leave nothing behind, block lines take the opening tag's indentation, blocks nest, and the output is byte-identical every run. Unknown names refuse before writing.
- `->inserts()` on a part writes an insert stub before a retained `mod:<anchor>` marker in a file owned by that parent. It uses fully qualified names and does not edit imports. A child command `mod:<root>.<part>` can grow an existing cluster.
- Members support PHP classes, migrations and plain files, including pages, Blade, Vue, React and CSS. Route aliases can start missing anchored route files. Load routes with Mod::routes() or a module provider.

Before writing a reusable recipe, record required packages, import aliases and configured model bases. Keep authorization, validation and frontend work explicit in the application's task. App recipes override package recipes; check `mod:list --json` for effective provenance and tree children.

## Scaffold member placement and module-owned generators

Use `ungrouped: true` on a scaffold member to place it where its file type goes without a group: an interface under `app/`, or a plain template under the app's `resources/`. Use `group: '{{ area }}'` with `->asks('area')` to place that member in its own group; pass the question's `--area` flag with `--no-interaction`. Group values receive the ordinary typo suggestions and new-group notices. Other members keep the scaffold's default group.

A member with `existing: 'keep'` is written once, then retained without a collision question, including when the scaffold uses `--force`. Inspect `--dry-run --json`: each file has `group` (`null` for ungrouped), `existing` (`"keep"` for this policy), and `exists`. Human plans label retained files "kept, exists".

A module can carry generator templates in its own `stubs/mod/`; every template there must use an `@module` path. Commands against that module choose its template or recipe before the app's, then the package's. Invokable classes in the module's `Scaffolds/` folder declare a public `$name` and `__invoke(Scaffold $scaffold)`; include `use Tey\Mod\Scaffolds\Scaffold;`. Alternatively register them with `Mod::scaffolds([...])` in a provider whose namespace is inside the module's namespace. Copying the module folder carries both kinds of generators. Use `mod:class Inventory:Scaffolds/StockReport` to start an invokable class.

Read `php artisan mod:list --json` for template and scaffold sources. Template sources include `module:Inventory`, `app` and `package:vendor/name`. Scaffold rows provide `vendor`, `name`, full `id`, `command`, provider `source`, Composer `package`, `overrides` and coded `notices`. `mod:list --vendor=acme` filters scaffold rows in human and JSON output. `mod:list -v` shows the template file, scaffold file or provider. Review the selected source before extending a copied module.

## Self-contained modules

In `modules`, everything a module needs (models, migrations, factories, policies, controllers, requests, actions, DTOs, events, listeners, jobs) is under `app/Modules/<Module>`. Load module routes with `Mod::routes()` in the app's routing configuration or with `loadRoutesFrom()` in the module provider. A module copied into another application that has mod installed with the same layout brings its routes, migrations, listeners, factories and policies with it. Its DTOs and view models extend base classes in `app/Support`; run `php artisan mod:bases` in the new application to write any that are missing.

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

## Read-only Boost tools and import metadata

When connected to Laravel Boost, read `mod-inventory` before generating files. It returns `php artisan mod:list --json` with the complete inventory. Boost is optional; Mod appends `mod-inventory`, `mod-plan`, `mod-survey` and `mod-fit` through `boost.mcp.tools.include` while preserving other includes.

Request a plan with an exact registered command and positional arguments/option tokens:

```json
{"command":"mod:resource-tabs.tab","arguments":["Inventory:Widget","History"]}
```

`mod-plan` enforces `--dry-run --json` and non-interactivity; it never applies the command. Check files, inserts, warnings and `would_write`. Missing answers remain warnings, and commands without preview support are refused. None of these tools writes files. Apply reviewed plans with `php artisan mod:*`.

Call `mod-survey` with `{}` to inspect how this app is actually organized, rather than only where generators would place new files. With no configured layout it uses `laravel`. The sorted JSON contains:

- `types`: every configured file type, including empty and non-discovered types, grouped by actual folder and namespace with every class and file. `classes` also lists declarations in unplaced files.
- `unplaced`: a count and project-relative paths for files no rule places. Ambiguous and callback-only placements appear separately in `unresolved`; invalid PHP appears in `unparsed`.
- `references`: directed `from`, `kind`, `to` edges for tokenizer-parsed class imports and class references, literal `Inertia::render` pages, registered routes to controllers, and policy/model pairs. Aliases, grouped imports and bracketed namespaces are supported. Function/constant imports and computed page names are not class/page edges.
- `sets`: connected app files (`basis: references`), co-located files (`basis: folder`), and overlapping model-centred sets (`basis: model`, with the model path in `anchor`). Model sets take direct neighbours and one outward reference hop, retaining procedural callers without traversing them or crossing into other models. This keeps shared base controllers and route files from merging every model's set. Vendor nodes do not join otherwise unrelated app files. `recurring_sets` groups sets with the same basis and file types; `naming_patterns` lists repeated file-type basename templates and their paths. These are evidence, not proof that files belong to one feature.
- `vendor`: referenced classes located under this app's `vendor/`, their paths and callers, using Composer's already loaded class maps and PSR-4 mappings without loading those classes.
- `git`: branch, commit, dirty state, origin remote URL and root commit. The remote and root commit identify the app across checkouts. Remote credentials, query strings and fragments are removed.

An excerpt (other types and fields omitted):

```json
{
  "references": [{"from": "App\\Http\\Controllers\\WidgetController", "kind": "page", "to": "page:Widgets/Show"}],
  "types": [{"groups": [{"classes": ["App\\Models\\Widget"], "files": ["app/Models/Widget.php"], "folder": "app/Models", "namespace": "App\\Models"}], "id": "model"}],
  "unplaced": {"count": 1, "paths": ["notes.txt"]}
}
```

The boundary is enforced in code: no arguments or alternate roots, no parent/sibling/home scans, no network calls and no writes. Paths are resolved inside `base_path()`; all discovered symlinks are skipped, and nested repositories and `node_modules/` are excluded. `.env*`, `storage/`, keys (`.key`, `.pem`), logs and database files are never opened or reported. PHP is tokenized, not executed; page files are not read for references. Only structure and class names are returned, never source or secret contents. Run the tool separately in each app if a person wants a comparison. Files larger than 1 MiB (1,048,576 bytes) are skipped before reading; `skipped` lists their relative paths with `size-limit`, and links with `symlink`. `bootstrap/cache`, `public/build`, `public/hot` and `public/storage` are excluded.

The survey reuses compiled placement and discovery relation definitions, not a cold discovery scan or autoloading policy callbacks. Registered Gate policies take precedence over layout conventions. It does not execute template/discovery loaders to fill missing registrations. Dynamic references, custom policy-guessing callbacks and opaque placement callbacks cannot be inferred safely.

Git commands run in `base_path()` with explicit local metadata, no global/system config, helpers, optional locks or network protocols. Git fields are null without a repository or when metadata would escape the boundary (including linked worktrees, alternate object stores or config includes). A skipped `.gitignore` also leaves Git fields null so Git cannot read a linked or oversized ignore file. A shallow checkout has a null root commit; nothing is fetched. Dirty detection compares staged metadata, tracked file metadata and safe file hashes; protected files are never hashed and excluded untracked files do not affect it. Timestamp-only changes may conservatively report dirty. It does not run Git clean filters. Keys and lists are sorted and duplicate observations removed, so repeated surveys of the same tree and runtime registrations are byte-identical.

Call `mod-fit` with a data-only `layout`, a `recipe`, or both; neither needs to be installed. A layout uses a shipped `base` (`laravel`, `modules`, `features`, `slices`, `type-first`, `ddd`), optional group `path`, `mounts` (`id`, `namespace`, `path`) and `types` (`id`, `in`, optional `suffix` or plain-file `extension`). It is isolated from app customizations. A recipe uses `name`, concrete `members` (`id`, expected project-relative `path`, optional `class` or `page` identity) and optional `tables`. Expand the intended scaffold invocation into paths; do not pass PHP, stubs, a registered recipe id or a path to a definition file. For example:

```json
{"recipe":{"name":"invite","members":[{"id":"model","path":"app/Models/Invitation.php","class":"App\\Models\\Invitation"},{"id":"accept","path":"app/Http/Controllers/AcceptInvitationController.php"}],"tables":["invitations"]},"variances":[{"member":"accept","path":"app/Http/Controllers/RegisterController.php","mechanism":"registration-flow"}]}
```

The sorted JSON returns `owned`, `unowned`, `conflicts`, `variances`, `missing`, `outside`, `configuration`, `vendor`, `skipped` and `unparsed`. Recipe matching tries the exact path, class identity, then the longest identical path tail (ignoring migration timestamps). Unique relocated roles are variances, not missing plus unowned; ties are conflicts. Renamed roles or alternative capabilities require agent-supplied `variances` with an existing permitted file and optional `mechanism`; their evidence is marked `agent`, not inferred. Missing members retain `can_mark_variance: true`. Outside class/page references and literal `Schema` table touches are attributed to the recipe. Static calls receiving closures are listed as unowned code configuration with file, line and target, never callback bodies or values; recipe attribution needs a member reference or a matched member file. Unrelated callbacks have a null recipe. These are review candidates, not semantic proof.

`mod-fit` reuses the survey's `Scope`, tokenizer and reverse mapping. It never reads proposed files outside the safe app scan, runs scanned code or installs a proposal. The same exclusions, symlink refusal and 1 MiB cap apply, with `vendor/` used only to classify classes through loaded Composer maps. No alternate scan roots, secrets, runtime data, writes or network calls; unknown input keys are refused. It does not query Git or infer computed references, renamed roles, dynamic table names, instance callbacks or callback variables. Read `vendor/tey/mod/docs/fit.md` for the complete contract and examples.

The additive `frontend.import_alias` is an object such as `{"alias":"@modules","root":"app/Modules"}`, or null without frontend declarations; ddd retains every frontend key with a null value. `frontend.view_namespace` remains unchanged. Use `@/` for files under `resources/js/`; use `@modules/` relative to the reported root for module files. Never traverse an alias with `../`. This metadata describes the intended mapping; check `wiring.vite_alias` to know whether it is installed.

## What to avoid

- Don't move existing code to match an example or a different layout.
- Don't edit `vendor/tey/mod`. Change the layout with `Mod::layout()` in a service provider, the output with stubs, and the settings in `config/mod.php`.
- Don't use `make:*` for a file that belongs in a module.
- Don't invent `mod:*` commands or options. Check `php artisan list mod` and `php artisan help mod:<type>`. Add a missing file type with a generator template or `->generates()` when it is within the requested scope.

The installed package's source and README describe its exact behaviour. Application instructions and the user's chosen scope take precedence over these examples.

## Building on mod

Packages must use only member-level @api symbols. Use Layout::fresh(...)->compiled() for an isolated shipped layout, CompiledLayout::place()/locate() for placement and ownership, and fileTypes()/fileType()/hasFileType() for Artifact\CompiledFileType metadata. Read artifact->fileType, namespace(), fqcn(), path() and context; keep ArtifactKind, naming policies, identities and placement rules internal. Do not use old kind/preset members or parameters. Register stubs with forFileType() and generator replacements with useFileType().

Host discovery is new Discovery(CompiledLayout $layout, DiscoveryOptions $options, string $basePath). Use discovery.file_types and DiscoveryDefinition::forFileType(). scan() cold-scans; readCache() only validates an existing cache and throws InvalidDiscoveryCache without scanning; cacheInventory() explicitly writes the host inventory. Preserve the host's fallback and deployment policy and use a separate host cache path. Read Inventory::ofType()/directories() and public DiscoveredArtifact values; cache payloads and fingerprints are internal.

A host Laravel provider uses ModServiceProvider::registerGenerationServices($app) without booting Mod app features, and resolves its own layout through layout()/resolveLayout() and fileTypeId(). Supported additions are plan(), currentPlan(), plannedRelations(), plannedRelation(), relatedFileType(), followRelation(), argumentsFor() and generateOwnedClass($fqcn, $fileType). Map native related roles once, including migration preflight; incoming dispatch ids are canonical. Inspect currentPlan()->relations through public relation id/fromFileType/toFileType/mode and RelationResolution::resolved(). Keep plannedRelationsTo(), relationsTo(), placeSibling(), inOption(), collision helpers and engine registries internal. Format a host domain from context->get('domain') and preserve its fallback; resolve paths with host utilities, including absolute and Windows paths.

Pass the public framework MigrationCreator and Composer to MigrationCommand. Exact framework creators are adapted inside Mod; custom application subclasses keep native dispatch with no plan or plan-specific callbacks. nativePathAllowed() controls explicit --path/--realpath. For isolated hosts, module-owned generator template rebinding is outside this helper; it needs the full Mod provider and app layouts. The full recipes and lifecycle promises are in vendor/tey/mod/docs/extending.md, Building on mod, and the signatures are pinned by the published API snapshot.

## Rename an existing cluster

`mod:rename OldGroup:OldName NewGroup:NewName --scaffold=<recipe> --dry-run --json` returns the read-only rename plan. Require a configured destination group and clean Git tree/index. Choose the effective source recipe from `mod:list --json`; pass every historical question answer and every repeated part, including grown parts. Creation defaults do not prove ownership. `mod:rename --answer=part.Item.question=<JSON>` forwards nested answers; ordinary recipe question flags, repeated `--tabs`, and negatable `--no-<question>` remain available where declared. Template slots use the recipe's own flags. Inspect moves, rewrites, retained members, scan roots, blockers in warnings, and source-located checklist items. Edited bodies are preserved; historical migrations and stable shared members stay in place. Never regenerate a missing member or replay inserts.

`mod:rename --yes --no-interaction` confirms execution only when the complete executor is installed; this planner fails closed without it. `mod:rename --table-migration` requests an exact optional new migration, never database execution. `mod:rename --recover` addresses an interrupted transaction without old/new arguments; `mod:rename --recover --dry-run --json` inspects through a separate read-only service. `mod-plan` always enforces `--dry-run --json --no-interaction`, including when callers supply `--recover` or `--yes`. `--json` without `--dry-run` refuses. There is no force flag or saved-plan application.

For app recipes that declare these answers, `mod:rename --base=<class> --model=<class> --tab=<value> --tabs=<value>` supplies the same explicit recipe inputs. Repeat list flags for every existing item. These flags are registered from app recipes, not built-in recipes.

### Optional table migration

Review historical migration and database-name checklist locations. Existing migrations, `$table` and Schema strings remain byte-identical. A class rename can change Eloquent's inferred table; a module-only move does not. Foreign keys, implicit route bindings and serialized identities still need application review.

Use `mod:rename Inventory:Widget Inventory:Gadget --scaffold=<app-recipe> --table-migration --dry-run --json` to inspect a complete reversible migration candidate alongside the rename plan. Interactive execution offers the migration before final confirmation and defaults to No. Non-interactive execution requires the boolean `--table-migration` to select generation. Compiled layout placement and the migration creator clock determine the new file; the sole rename executor stages it and owns rollback. Never run `migrate` as part of rename.

Framework HasFactory and SoftDeletes traits are supported without loading models; other traits and trait adaptations require review. Do not guess computed/inherited/trait-provided table names or instantiate a model to discover them. Ambiguous names and unsupported app-custom native migration creators block selected generation with a corrective diagnostic. Omit the flag for report-only behaviour, then create a reviewed migration separately. An explicit unchanged `$table` must not be reported as automatically moving to the new inferred table.

### Frontend reference review

The shipped `resources/js/rename/helper.cjs` reads the target app's installed Babel 7.29.x and, for Vue, compiler-sfc 3.5.x parsers. It never installs packages or writes sources. Review static imports, neighbouring relative imports of moved files, exact Vue component identities and Blade literal identities in the plan. Keep `@modules/` below its compiled module root and `@/` below app resources; alias imports cannot traverse `../`. PHP render/view identities remain with the PHP contributor. When Node/parsers are unavailable, source syntax is unsupported, or an identity/import is computed, leave the bytes unchanged and follow the located checklist's `after_file` and `suggestion`. CSS URLs and unrelated labels remain review items. Read the installed `resources/js/rename/README.md` for the protocol/capability matrix. Do not substitute regex rewrites, dependency installation or a saved helper response for a fresh validated rename plan.

### PHP reference review

PHP edits bind mapped class names against each original namespace/import table, including grouped imports, explicit aliases, types, attributes, inheritance, traits, `new`, `instanceof`, static calls and `::class`. Explicit aliases remain; unaliased bound names change with their import. Import/declaration collisions and malformed PHP block before execution. Manually edited bodies, comments and line endings are preserved; rename never re-renders generator templates.

Review exact page-name edits only in bound `Inertia::render`/`inertia` calls, and exact view-name edits only in `view` calls. Plain labels and computed identities remain review items. Runtime route/URI, translation, configuration, database, class-string, morph/serialization and historical migration values remain unchanged. Checklist rows use original `file` and `line`, with prospective `after_file`; suggestions are advisory and dynamic analysis is incomplete. PHP tokens in Blade have separate byte ownership from frontend directives and markup. Never infer that a green PHP plan establishes database, frontend or persisted-data compatibility.

### Applying and recovering

Apply a recipe-owned rename only after reviewing its complete plan. `mod:rename ... --yes --no-interaction` stages moves, supported reference edits and selected new table migrations together without committing or running a database migration. Execution holds a worktree-specific lock before planning, validates outputs and destinations, and rechecks input bytes, modes, root membership, read dependencies and Git state immediately before mutation. Changed inputs preserve outside edits and require a fresh preview.

On ordinary failure, the journal restores only transaction-owned paths, bytes, permissions, index entries and created empty directories. Compare-before-restore preserves editor changes; incomplete rollback reports `Rollback incomplete; recovery required` with the journal path and remaining operations. An interrupted journal blocks fresh execution.

Inspect recovery with `mod:rename --recover --dry-run --json`, or `mod-plan` for `mod:rename` with `--recover`; tools remain read-only and do not acquire locks. Recovery takes no cluster arguments or recipe options. `mod:rename --recover` defaults confirmation to No; `mod:rename --recover --yes --no-interaction` restores after comparison. Git resolves the worktree-specific `mod-rename/` journal and lock directory, including linked worktrees. Unrelated working and staged changes remain; changed transaction-owned bytes/modes/index entries block restoration until the user preserves and resolves the reported conflicts. Repeated recovery is safe. Never substitute reset, clean, stash or an automatic commit.

### Recipe and recovery limits

Declare native option-generated companions as explicit recipe members; model --all/--migration/--factory do not prove rename membership. All original answers, including defaults and grown parts, are required. Stable keep/shared members stay in place; changing kept identities block. Ungrouped is placement, not shared ownership. Unaccounted candidates require recipe review, never prefix-only ownership, force or an exclusion bypass.

Use compiled paths and scan_roots. Scans cover compiled roots plus app/bootstrap/config/resources/routes/tests and root lang when present, excluding dependencies/build/cache/Git metadata/generator templates. Historical migrations are immutable. The ddd defaults keep Controllers/Requests/Middleware outside Http, and frontend/views/routes opt-in. Rename neither upgrades layout nor moves an entire module. Execution requires the app to be the Git worktree root; nested apps are refused. Recovery guarantees concern process termination, not machine power loss or arbitrary external writer serialization. Index restoration requires the filesystem's exclusive hard-link support.

After success inspect git diff --cached and build/type-check/request the renamed application. Fallback preserves reference bytes and does not establish runtime correctness until reported manual fixes are applied. Read vendor/tey/mod/docs/renaming.md for all flags, exact migration, parser matrix and recovery commands. Building on mod host guidance remains in docs/extending.md; rename services remain internal.

## Laravel MCP generators

With laravel/mcp installed, modules and ddd expose `mod:mcp-tool` (`mod:mcptool`), `mod:mcp-server` (`mod:mcpserver`), `mod:mcp-resource` (`mod:mcpresource`) and `mod:mcp-prompt` (`mod:mcpprompt`). They use the package's native generators, published stubs and --force option. Modules place them at @module/Mcp/{Tools,Servers,Resources,Prompts}; DDD uses app/Modules/<Domain>/Mcp/... for application-facing classes. Use the shared placement flags and --dry-run --json to check the single-class plan before writing.

These four ids also use native adapters when declared in custom layouts. Explicit Mod::generators()->useFileType() overrides win. Other installed make:* commands are not automatically adapted: an unsupported id uses the generic class generator. In particular, make:mcp-app-resource writes a companion Blade view and has no Mod adapter. Use it directly or provide an adapter that accounts for all companion writes in previews and collision checks. See docs/extending.md.
