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

## HTTP and frontend paths

Module HTTP file types use Http/Controllers, Http/Requests, Http/Middleware and Http/Resources. DDD keeps domain API resources in src/Domain/<Domain>/Resources and application HTTP classes in app/Modules/<Domain>/Controllers, Requests and Middleware. The ddd preset declares no frontend or routes roots. Module resources/ and routes/ roots contain plain files and are excluded from class discovery.

Use ->frontend(pages: ..., components: ..., css: ..., views: ..., pageName: ...) to customize frontend paths. Omitted arguments retain defaults; ->extends() copies the configuration. Built-in page folders follow the app’s pages/Pages casing, while explicit overrides retain their spelling. Inspect frontend paths and page_name in mod:list --json rather than assuming an Inertia stack. Compilation checks case-only folder collisions; move frontend paths under ui/ when a custom API Resources/ folder would clash with resources/.

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

- `mod:page <group>:<nested-name> --dry-run --json` previews the detected stack's page path and identity; `mod:page <group>:<nested-name> --no-interaction` generates it. Use `--force` only when overwriting the existing page is intended.
- Plain generator templates use their filename's first dot as the command/extension boundary. `{{ alias.name }}`, `.import`, `.tag`, `.path`, and the bare component alias come from the resolved layout. Keep Vue/Blade expressions unknown to mod, or escape them with `@{{ … }}`. Use explicit forms for known names to avoid informational bare-placeholder warnings.
- Page variants include the extension, such as `stubs/mod.page.crud-index.tsx.stub`. Preview complete scaffold plans before writing; missing variants and anchors refuse before generated files change.
- `mod:template --from=<plain-file> --into=<template-path> --dry-run --json` reports `mentions[]`. The real run copies the bytes unchanged. Generalize the reported mentions manually with known placeholders.

Use `@modules/...` imports for files under the layout group root and `@/...` for files under `resources/js`. Never cross an alias root with `../`. A part keeps the cluster name in `{name}`; use its question placeholder (such as `{widget}`) for the part value.

## Installing Inertia

Before generating module pages, run `mod:install inertia --no-interaction`. Use `mod:install inertia --dry-run --json` to inspect the shared plan, including each file's before and after contents, warnings and `would_write`. Human `--dry-run` previews the same changes. The command detects Vue or React, preserves app page casing and reads the compiled layout's frontend paths. It wires the vendor resolver, `@modules` in Vite and TypeScript, module view refresh paths and Tailwind v3 or v4 scanning. A second run says "Already wired." Blade apps need no install. Mirrored pages already resolve through the app's glob, so their app entry is left alone.

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

Use a scaffold when several file types form a reusable recipe. Register it in a provider with `Mod::scaffold()` and include `use Tey\Mod\Scaffolds\Scaffold;` in every scaffold example. Include `use Tey\Mod\Scaffolds\Part;` when typing a part closure. Chain `->makes('model', as: 'model')` and other members; their paths follow the active layout. `Mod::scaffolds()` also accepts invokable recipe classes, and a layout's `->scaffolds()` overrides a global recipe.

- `as:` names sibling aliases: `{{ model }}` is the short class name, `{{ model.fqcn }}` is the full name. Name forms chain left to right (`{{ name.plural.kebab }}`).
- `stub: 'crud'` selects `stubs/mod.<type>.crud.stub`. Create that variant before non-interactive generation; variant publication happens only after the plan is accepted.
- `->include('recipe')` reuses a recipe. A later member with the same alias replaces the included member.
- Read the complete file and insert plan before writing. Pass `--no-interaction` and required question options. Choose `--skip-existing` to keep existing files or `--force` to overwrite them.
- `->asks()` declares questions and options; `->each()` repeats a `->part()`. Parts can use another scaffold and pass values with `with:`. Use `configure:` for a closure after named arguments.
- `->inserts()` on a part writes an insert stub before a retained `mod:<anchor>` marker in a file owned by that parent. It uses fully qualified names and does not edit imports. A child command `mod:<root>.<part>` can grow an existing cluster.
- Members support PHP classes, migrations and plain files, including pages, Blade, Vue, React and CSS. Route aliases can start missing anchored route files. Load routes with Mod::routes() or a module provider.

Before writing a reusable recipe, record required packages, import aliases and configured model bases. Keep authorization, validation and frontend work explicit in the application's task. App recipes override package recipes; check `mod:list --json` for effective provenance and tree children.

## Scaffold member placement and module-owned generators

Use `ungrouped: true` on a scaffold member to place it where its file type goes without a group: an interface under `app/`, or a plain template under the app's `resources/`. Use `group: '{{ area }}'` with `->asks('area')` to place that member in its own group; pass the question's `--area` flag with `--no-interaction`. Group values receive the ordinary typo suggestions and new-group notices. Other members keep the scaffold's default group.

A member with `existing: 'keep'` is written once, then retained without a collision question, including when the scaffold uses `--force`. Inspect `--dry-run --json`: each file has `group` (`null` for ungrouped), `existing` (`"keep"` for this policy), and `exists`. Human plans label retained files "kept, exists".

A module can carry generator templates in its own `stubs/mod/`; every template there must use an `@module` path. Commands against that module choose its template or recipe before the app's, then the package's. Invokable classes in the module's `Scaffolds/` folder declare a public `$name` and `__invoke(Scaffold $scaffold)`; include `use Tey\Mod\Scaffolds\Scaffold;`. Alternatively register them with `Mod::scaffolds([...])` in a provider whose namespace is inside the module's namespace. Copying the module folder carries both kinds of generators. Use `mod:class Inventory:Scaffolds/StockReport` to start an invokable class.

Read `php artisan mod:list --json` for template and scaffold sources: `module:Inventory`, `app`, or `package:vendor/name`. `mod:list -v` shows the template file, scaffold file or provider. Review the selected source before extending a copied module.

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

When connected to Laravel Boost, read `mod-inventory` before generating files. It returns `php artisan mod:list --json` with the complete inventory. Boost is optional; Mod appends `mod-inventory` and `mod-plan` through `boost.mcp.tools.include` while preserving other includes.

Request a plan with an exact registered command and positional arguments/option tokens:

```json
{"command":"mod:resource-tabs.tab","arguments":["Inventory:Widget","History"]}
```

`mod-plan` enforces `--dry-run --json` and non-interactivity; it never applies the command. Check files, inserts, warnings and `would_write`. Missing answers remain warnings, and commands without preview support are refused. Neither tool writes files. Apply reviewed plans with `php artisan mod:*`.

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

## Optional table migration during rename

Review historical migration and database-name checklist locations. Existing migrations, `$table` and Schema strings remain byte-identical. A class rename can change Eloquent's inferred table; a module-only move does not. Foreign keys, implicit route bindings and serialized identities still need application review.

Use `mod:rename Inventory:Widget Inventory:Gadget --scaffold=<app-recipe> --table-migration --dry-run --json` to inspect a complete reversible migration candidate alongside the rename plan. Interactive execution offers the migration before final confirmation and defaults to No. Non-interactive execution requires the boolean `--table-migration` to select generation. Compiled layout placement and the migration creator clock determine the new file; the sole rename executor stages it and owns rollback. Never run `migrate` as part of rename.

Framework HasFactory and SoftDeletes traits are supported without loading models; other traits and trait adaptations require review. Do not guess computed/inherited/trait-provided table names or instantiate a model to discover them. Ambiguous names and unsupported app-custom native migration creators block selected generation with a corrective diagnostic. Omit the flag for report-only behaviour, then create a reviewed migration separately. An explicit unchanged `$table` must not be reported as automatically moving to the new inferred table.

## Frontend rename review

The shipped `resources/js/rename/helper.cjs` reads the target app's installed Babel 7.29.x and, for Vue, compiler-sfc 3.5.x parsers. It never installs packages or writes sources. Review static imports, neighbouring relative imports of moved files, exact Vue component identities and Blade literal identities in the plan. Keep `@modules/` below its compiled module root and `@/` below app resources; alias imports cannot traverse `../`. PHP render/view identities remain with the PHP contributor. When Node/parsers are unavailable, source syntax is unsupported, or an identity/import is computed, leave the bytes unchanged and follow the located checklist's `after_file` and `suggestion`. CSS URLs and unrelated labels remain review items. Read the installed `resources/js/rename/README.md` for the protocol/capability matrix. Do not substitute regex rewrites, dependency installation or a saved helper response for a fresh validated rename plan.
