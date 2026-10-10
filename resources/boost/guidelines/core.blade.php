## Mod

This application uses `tey/mod` to place generated files in its own layout and to discover its providers, commands, listeners and migrations. Run `php artisan mod:list --json` first to inspect file types, templates, scaffolds and discovery. Before placing a file, check the configured layout, not the package defaults: `'layout'` in `config/mod.php`, any `Mod::layout()` calls in service providers, and the Composer PSR-4 mappings. Prefer the matching `mod:*` generator over `make:*`, and give its placement explicitly: `Knowledge:Document`, `--in=Knowledge`, or the layout's own option such as `--module=Knowledge`. Use the `mod-development` skill when generating files or changing layouts, stubs, base classes or discovery.

After choosing `ddd` or adding a layout root outside `app/`, run `php artisan mod:autoload` to add missing Composer PSR-4 mappings and reload the autoloader. Use `--dry-run` to preview the entries or `--no-dump` when a script runs Composer itself.

Always pass `--no-interaction` and the options that answer required questions. When a class shape repeats, create a generator template with `mod:template <type> <name>` or `mod:template --from=<Class> --into=<path> --no-interaction`. When several file types repeat together, register a scaffold using `->makes()` and sibling aliases. Include `use Tey\Mod\Scaffolds\Scaffold;` in every scaffold example, and `use Tey\Mod\Scaffolds\Part;` when typing a part closure. Inspect the whole plan and choose collision options explicitly. Use `->generates()`, `->mounts()`, `->relates()` and `->excludes()` in layout declarations.

Before writing, run the chosen command with `--dry-run --json`. Read its files, inserts, warnings, and `would_write` flag. The preview writes nothing and emits only JSON; required answers come from flags or defaults. A missing answer appears as a warning with `would_write: false`, even though the preview exits 0. Use `--dry-run` alone for a text table. Each new command or option must be documented in this guideline or the mod-development skill; the package checks coverage in its test suite.

## HTTP and frontend paths

Module HTTP file types use Http/Controllers, Http/Requests, Http/Middleware and Http/Resources. DDD keeps domain API resources in src/Domain/<Domain>/Resources and application HTTP classes in app/Modules/<Domain>/Controllers, Requests and Middleware. The ddd preset declares no frontend or routes roots. Module resources/ and routes/ roots contain plain files and are excluded from class discovery.

Use ->frontend(pages: ..., components: ..., css: ..., views: ..., pageName: ...) to customize frontend paths. Omitted arguments retain defaults; ->extends() copies the configuration. Built-in page folders follow the app’s pages/Pages casing, while explicit overrides retain their spelling. Inspect frontend paths and page_name in mod:list --json rather than assuming an Inertia stack. Compilation checks case-only folder collisions; move frontend paths under ui/ when a custom API Resources/ folder would clash with resources/.

## Module views and Blade components

- `mod:view Inventory:widgets.show` runs Laravel's view generator in the group's views folder. Options: `mod:view --extension=blade.php --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use the result with `view('inventory::widgets.show')`.
- `mod:component Inventory:StockBadge --view` creates an anonymous Blade component. `mod:component Inventory:WidgetTable` creates a class in `View/Components` and its view. Options: `mod:component --view --inline --path --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use `&lt;x-inventory::stock-badge /&gt;`; Vue and React components come from generator templates.
- `mod:mail Inventory:WidgetRestocked --markdown=mail.widget-restocked` writes both the mailable and its view, using `inventory::mail.widget-restocked`. `mod:mail --view=mail.widget-restocked` writes a plain Blade view. `mod:notification --markdown=mail.widget-restocked` qualifies its Markdown view the same way. Their `--dry-run --json` plans include both files and the view identity; `--force` replaces planned views.
- View namespaces register after providers boot, even without route or class discovery. Groups without views are omitted. Reserved namespaces (`mail`, `notifications`, `pagination`) and existing namespace clashes are skipped with a warning. Module views participate in `view:cache`.
- DDD declares no view folders or namespaces; markdown mail and notification views use Laravel’s resources/views and unqualified names. Opt in with ->frontend() on an extended layout. Type-first grouped views use kebab subfolders such as `resources/views/inventory`; ungrouped views stay in `resources/views` with no namespace. `mod:list --json` adds a `views` section with group, namespace, path and component tags. View identities expose name, tag and path for plain-file members.

## Installing Inertia

Before generating module pages, run `mod:install inertia --no-interaction`. Use `mod:install inertia --dry-run --json` to inspect the shared plan, including each file's before and after contents, warnings and `would_write`. Human `--dry-run` previews the same changes. The command detects Vue or React, preserves app page casing and reads the compiled layout's frontend paths. It wires the vendor resolver, `@modules` in Vite and TypeScript, module view refresh paths and Tailwind v3 or v4 scanning. A second run says "Already wired." Blade apps need no install. Mirrored pages already resolve through the app's glob, so their app entry is left alone.

The package exports `resolveModulePage(name, appPages, modulePages)` from `vendor/tey/mod/resources/js/inertia`; pass literal `import.meta.glob` maps from the app. Render a module page as `Inventory::Widget/Index`. Missing pages throw with the file name; module names never fall back to app pages. A custom resolver or unrecognized config is preserved; read the warning and add the printed manual wiring. `mod:list --json` exposes the boolean `wiring.inertia`, `wiring.vite_alias` and `wiring.tailwind` values detected from the current files.

## Module routes

Module routes load only through `Mod::routes(only: [...], except: [...])`. Put the call in `withRouting(then: fn () => Mod::routes())` or inside a Laravel route group to inherit its middleware, URI prefix and name prefix. `config('mod.routes.order')` loads listed modules first, then the rest alphabetically; unknown names warn. Duplicate module calls are refused with both call locations. Cached routes make the call a no-op.

Use `mod:routes Inventory --api --console` for route files: web uses `web`, API uses `api` and `/api`, console loads only in the console. Use `mod:route-registrar Inventory` for a `RegistersRoutes` implementation with static `web()` and `api()` methods. Files load before registrars, and provider-loaded files are skipped. Both commands accept `--force` for replacement and `--dry-run --json` for a plan without writes. Refusals without a terminal name `--force`.

Scaffold inserts use `into: 'routes'` for the module's web file or `into: 'routes.web'` / `into: 'routes.api'` for registrar methods when present. Missing files start with the route anchor. Keep bindings and rate limiters in providers. `mod:list --json` reports the `routes` entries with `group`, `entrypoint`, `kind`, `middleware_group`, `order`, and `loaded_by`; listing never calls a registrar.

## Frontend and plain files

Use `mod:page Inventory:Widget/Index` to create a minimal Inertia page. The detected Vue or React stack determines its extension and casing; without Inertia in `package.json`, the command explains how to declare it. The app's `stubs/mod.page.vue.stub`, `.tsx.stub` or `.jsx.stub` replaces the default. Page names follow the compiled `page_name` pattern; take `identity.name` from `--dry-run --json` rather than deriving it.

Generator templates below `stubs/mod/` may write plain files under resources or routes. The first dot separates the command name from the extension: `card.blade.php.stub` gives the `card` command. Vue files are StudlyCase, React/Blade/Markdown/CSS are kebab-case, and `.ts`/`.js` keep the typed name. Override a file type with `case:`.

Plain files replace only known placeholders. Unknown Vue and Blade expressions pass through; prefix a placeholder with @ to emit it literally. Bare known placeholders produce a warning with the template path and line; use explicit forms such as name.studly. A known placeholder nested inside a framework expression is replaced while the outer expression remains. Lists support .json and .array; prose supports .headline.

Plain scaffold members expose the component name through their bare alias, plus `.name`, `.import` through `@modules` for files under the group root or `@` for files under `resources/js`, `.tag` for Blade components, and `.path`. A `file` question accepts a project-relative plain file and exposes the same forms. Variants use `stubs/mod.page.<variant>.<extension>.stub`; a missing variant prompts to create it, and a non-interactive run names the file to supply. Inserts use explicit anchors in any comment style, including Vue script and template anchors.

`mod:template --from=<plain-file> --into=<template-path>` copies the source unchanged, derives its extension, and reports name/group mentions for manual generalization. Its JSON preview includes `mentions[]` and writes nothing.

## Scaffold member placement and module-owned generators

Use `ungrouped: true` on a scaffold member to place it where its file type goes without a group: an interface under `app/`, or a plain template under the app's `resources/`. Use `group:` with the `area` question placeholder with `->asks('area')` to place that member in its own group; pass the question's `--area` flag with `--no-interaction`. Group values receive the ordinary typo suggestions and new-group notices. Other members keep the scaffold's default group.

A member with `existing: 'keep'` is written once, then retained without a collision question, including when the scaffold uses `--force`. Inspect `--dry-run --json`: each file has `group` (`null` for ungrouped), `existing` (`"keep"` for this policy), and `exists`. Human plans label retained files "kept, exists".

A module can carry generator templates in its own `stubs/mod/`; every template there must use an `@module` path. Commands against that module choose its template or recipe before the app's, then the package's. Invokable classes in the module's `Scaffolds/` folder declare a public `$name` and `__invoke(Scaffold $scaffold)`; include `use Tey\Mod\Scaffolds\Scaffold;`. Alternatively register them with `Mod::scaffolds([...])` in a provider whose namespace is inside the module's namespace. Copying the module folder carries both kinds of generators. Use `mod:class Inventory:Scaffolds/StockReport` to start an invokable class.

Read `php artisan mod:list --json` for template and scaffold sources: `module:Inventory`, `app`, or `package:vendor/name`. `mod:list -v` shows the template file, scaffold file or provider. Review the selected source before extending a copied module.

## Read-only Boost tools and import metadata

Read `mod-inventory` first when connected to Laravel Boost. It returns the same inventory as `php artisan mod:list --json`, including file types, templates, scaffolds, stack, frontend paths, views, routes and wiring. Mod adds its tools through `boost.mcp.tools.include` only when Boost is installed; preserve other included tools. Boost is optional.

Use `mod-plan` with an exact registered `mod:*` command and an array of positional arguments and option tokens: `{"command":"mod:resource-tabs.tab","arguments":["Inventory:Widget","History"]}`. The tool enforces `--dry-run --json` and non-interactivity. Missing answers remain plan warnings; inspect `would_write`, files and inserts before proceeding. Commands without JSON preview support are refused. Both tools are read-only; apply reviewed changes with `php artisan mod:*`.

`frontend.import_alias` describes the alias and its project-relative root, for example `{"alias":"@modules","root":"app/Modules"}`. `frontend.view_namespace` remains available. For imports, files under `resources/js/` use the app alias `@/`; module files use `@modules/` relative to the reported root. Do not put `../` into an alias import. The alias metadata describes the intended mapping; `wiring.vite_alias` reports whether the app has that mapping. A layout without frontend declarations, including ddd, retains every frontend key with a null value.

## Building on mod

Packages must use only member-level @api symbols. Use Layout::fresh(...)->compiled() for an isolated shipped layout, CompiledLayout::place()/locate() for placement and ownership, and fileTypes()/fileType()/hasFileType() for Artifact\CompiledFileType metadata. Read artifact->fileType, namespace(), fqcn(), path() and context; keep ArtifactKind, naming policies, identities and placement rules internal. Do not use old kind/preset members or parameters. Register stubs with forFileType() and generator replacements with useFileType().

Host discovery is new Discovery(CompiledLayout $layout, DiscoveryOptions $options, string $basePath). Use discovery.file_types and DiscoveryDefinition::forFileType(). scan() cold-scans; readCache() only validates an existing cache and throws InvalidDiscoveryCache without scanning; cacheInventory() explicitly writes the host inventory. Preserve the host's fallback and deployment policy and use a separate host cache path. Read Inventory::ofType()/directories() and public DiscoveredArtifact values; cache payloads and fingerprints are internal.

A host Laravel provider uses ModServiceProvider::registerGenerationServices($app) without booting Mod app features, and resolves its own layout through layout()/resolveLayout() and fileTypeId(). Supported additions are plan(), currentPlan(), plannedRelations(), plannedRelation(), relatedFileType(), followRelation(), argumentsFor() and generateOwnedClass($fqcn, $fileType). Map native related roles once, including migration preflight; incoming dispatch ids are canonical. Inspect currentPlan()->relations through public relation id/fromFileType/toFileType/mode and RelationResolution::resolved(). Keep plannedRelationsTo(), relationsTo(), placeSibling(), inOption(), collision helpers and engine registries internal. Format a host domain from context->get('domain') and preserve its fallback; resolve paths with host utilities, including absolute and Windows paths.

Pass the public framework MigrationCreator and Composer to MigrationCommand. Exact framework creators are adapted inside Mod; custom application subclasses keep native dispatch with no plan or plan-specific callbacks. nativePathAllowed() controls explicit --path/--realpath. For isolated hosts, module-owned generator template rebinding is outside this helper; it needs the full Mod provider and app layouts. The full recipes and lifecycle promises are in vendor/tey/mod/docs/extending.md, Building on mod, and the signatures are pinned by the published API snapshot.

### Rename plans

Preview a recipe-owned cluster with `mod:rename Group:Old Group:New --scaffold=<recipe> --dry-run --json`. Pass complete historical question/part flags (including repeated `--tabs` where declared), or qualified nested `--answer=part.Item.question=<JSON>`; keep defaults are not historical evidence. `mod:rename --yes --no-interaction` is the final confirmation path after review. `mod:rename --table-migration` requests a new reversible migration candidate; it never runs migrations. `mod:rename --recover` bypasses normal cluster planning, while `mod:rename --recover --dry-run --json` is read-only inspection. Execution is unavailable until the sole executor is installed. `mod-plan` remains read-only even when supplied recovery or confirmation flags. Follow `mod:list --json` rename capabilities and review all blockers/checklist entries.

For app recipes that declare these answers, `mod:rename --base=<class> --model=<class> --tab=<value> --tabs=<value>` supplies the same explicit recipe inputs. Repeat list flags for every existing item. These flags are registered from app recipes, not built-in recipes.

## Optional table migration during rename

Historical migration files and database strings stay unchanged. For an Eloquent model with a directly declared framework parent and no custom table logic, renaming the class can change its inferred table. Review the located table, foreign-key, route-binding and serialization checklist before using the renamed model. An explicit `$table` remains on the same table; a module-only move has no table rename to offer.

Interactive execution offers a reversible migration before the final plan, defaulting to No. Agents select it with `mod:rename Inventory:Widget Inventory:Gadget --scaffold=<app-recipe> --table-migration --yes --no-interaction`. Preview with `--dry-run --json` never prompts or writes; a selected migration is a complete new `files` entry at the compiled migration placement. The rename executor stages that candidate with the other changes and owns rollback. Mod never queries the schema or runs migrations.

Computed table names, custom connections, inherited custom parents and unresolved model traits require review; selected migration generation refuses ambiguous names. An app-custom native migration creator without exact read-only planning is also refused. Omit `--table-migration` to rename with a report and create a reviewed migration separately. The flag does not rewrite historical files, pin `$table`, or resolve every runtime compatibility concern.

### Frontend rename parsing and fallback

`mod:rename` uses the shipped `resources/js/rename/helper.cjs` with the target app's installed parsers; it never installs Node packages. Supported parser series are Babel 7.29.x for JS/TS/JSX/TSX and Vue compiler-sfc 3.5.x plus Babel for Vue scripts/templates. Static imports resolve to original files and retain their configured `@modules/`, `@/` or relative form after moves, including neighbouring relative imports. Known static Vue component identities and Blade directive/anonymous-component literals use resolved member identities. PHP literal identities belong to the PHP contributor. Missing Node/parsers, unsupported syntax, computed values and CSS URLs remain original file:line checklist items; inspect `after_file` and `suggestion` before confirming. User labels and comments stay unchanged. Parser read dependencies are part of immutable input validation; JSON output is never mixed with subprocess diagnostics. The helper protocol/capability matrix is shipped at `resources/js/rename/README.md`.

### PHP references and runtime review during rename

Rename binds PHP class references through each original namespace and import table. It updates mapped declarations, imports (including groups and aliases), types, attributes, inheritance, traits, instantiation and static class references. Explicit aliases stay unchanged; a target import collision or malformed PHP blocks the plan. Generator templates never replace manually edited bodies. Unchanged bytes, comments and line endings are preserved.

Only exact mapped page names in bound Inertia render calls or the inertia helper, and exact mapped view names in the view helper, receive PHP identity edits. Plain strings, computed identities, route URIs/names, translation/configuration values, database names, serialization and historical migration references stay unchanged for review. Read every original `file`, `line`, `category`, suggestion and prospective `after_file` in the checklist. The checklist is advisory and cannot establish complete runtime compatibility. PHP regions in Blade are analysed separately from frontend directive/markup identities; unavailable frontend parsing never permits guessed replacements.

## Applying and recovering a rename

`mod:rename` computes a fresh complete plan under one worktree lock, previews every move, rewrite, retained member and review item, then defaults confirmation to No. Pass `--yes --no-interaction` to apply a reviewed rename. Moves, supported consumer rewrites and any explicitly selected reversible table migration form one staged Git diff; the command never commits or executes a migration.

Execution validates output PHP and destinations, then rechecks input bytes, permissions, scan-root membership, recipe/layout/template/parser dependencies and Git state before writing. A changed input refuses and preserves the editor's changes. Ordinary failures restore transaction-owned paths, bytes, permissions, index entries and created empty directories; outside changes are compared before restoration. Incomplete restoration reports `Rollback incomplete; recovery required` and the journal path.

After interruption, fresh execution blocks. Inspect with `mod:rename --recover --dry-run --json` or `mod-plan` using command `mod:rename` and argument `--recover`. Inspection never takes a lock or restores anything. Recovery takes no cluster arguments, scaffold/recipe answers or table-migration flag: use `mod:rename --recover`, default No, or `mod:rename --recover --yes --no-interaction`. Journals and locks live under Git's actual worktree metadata directory in `mod-rename/`; linked worktrees have separate state. Recovery preserves unrelated working/staged edits and refuses changed transaction-owned bytes, permissions or index entries. Preserve those outside edits separately and resolve the reported conflicts before retrying recovery. Repeated recovery is safe; never erase user changes with reset, clean or stash.
