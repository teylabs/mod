## Mod

This application uses `tey/mod` to place generated files in its own layout and to discover its providers, commands, listeners and migrations. Run `php artisan mod:list --json` first to inspect file types, templates, scaffolds and discovery. Before placing a file, check the configured layout, not the package defaults: `'layout'` in `config/mod.php`, any `Mod::layout()` calls in service providers, and the Composer PSR-4 mappings. Prefer the matching `mod:*` generator over `make:*`, and give its placement explicitly: `Knowledge:Document`, `--in=Knowledge`, or the layout's own option such as `--module=Knowledge`. Use the `mod-development` skill when generating files or changing layouts, stubs, base classes or discovery.

After choosing `ddd` or adding a layout root outside `app/`, run `php artisan mod:autoload` to add missing Composer PSR-4 mappings and reload the autoloader. Use `--dry-run` to preview the entries or `--no-dump` when a script runs Composer itself.

Always pass `--no-interaction` and the options that answer required questions. When a class shape repeats, create a generator template with `mod:template <type> <name>` or `mod:template --from=<Class> --into=<path> --no-interaction`. When several file types repeat together, register a scaffold using `->makes()` and sibling aliases. Include `use Tey\Mod\Scaffolds\Scaffold;` in every scaffold example, and `use Tey\Mod\Scaffolds\Part;` when typing a part closure. Inspect the whole plan and choose collision options explicitly. Use `->generates()`, `->mounts()`, `->relates()` and `->excludes()` in layout declarations.

Before writing, run the chosen command with `--dry-run --json`. Read its files, inserts, warnings, and `would_write` flag. The preview writes nothing and emits only JSON; required answers come from flags or defaults. A missing answer appears as a warning with `would_write: false`, even though the preview exits 0. Use `--dry-run` alone for a text table. Each new command or option must be documented in this guideline or the mod-development skill; the package checks coverage in its test suite.


## Module views and Blade components (0.3)

- `mod:view Inventory:widgets.show` runs Laravel's view generator in the group's views folder. Options: `mod:view --extension=blade.php --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use the result with `view('inventory::widgets.show')`.
- `mod:component Inventory:StockBadge --view` creates an anonymous Blade component. `mod:component Inventory:WidgetTable` creates a class in `View/Components` and its view. Options: `mod:component --view --inline --path --test --pest --phpunit --force --in --dry-run --json --no-interaction`. Use `&lt;x-inventory::stock-badge /&gt;`; Vue and React components come from generator templates.
- `mod:mail Inventory:WidgetRestocked --markdown=mail.widget-restocked` writes both the mailable and its view, using `inventory::mail.widget-restocked`. `mod:mail --view=mail.widget-restocked` writes a plain Blade view. `mod:notification --markdown=mail.widget-restocked` qualifies its Markdown view the same way. Their `--dry-run --json` plans include both files and the view identity; `--force` replaces planned views.
- View namespaces register after providers boot, even without route or class discovery. Groups without views are omitted. Reserved namespaces (`mail`, `notifications`, `pagination`) and existing namespace clashes are skipped with a warning. Module views participate in `view:cache`.
- DDD views live under the application root. Type-first grouped views use kebab subfolders such as `resources/views/inventory`; ungrouped views stay in `resources/views` with no namespace. `mod:list --json` adds a `views` section with group, namespace, path and component tags. View identities expose name, tag and path for plain-file members.

## Installing Inertia (0.3 · L7)

Before generating module pages, run `mod:install inertia --no-interaction`. Use `mod:install inertia --dry-run --json` to inspect the shared plan, including each file's before and after contents, warnings and `would_write`. Human `--dry-run` previews the same changes. The command detects Vue or React, preserves app page casing and reads the compiled layout's frontend paths. It wires the vendor resolver, `@modules` in Vite and TypeScript, module view refresh paths and Tailwind v3 or v4 scanning. A second run says "Already wired." Blade apps need no install. Mirrored pages already resolve through the app's glob, so their app entry is left alone.

The package exports `resolveModulePage(name, appPages, modulePages)` from `vendor/tey/mod/resources/js/inertia`; pass literal `import.meta.glob` maps from the app. Render a module page as `Inventory::Widget/Index`. Missing pages throw with the file name; module names never fall back to app pages. A custom resolver or unrecognized config is preserved; read the warning and add the printed manual wiring. `mod:list --json` exposes the boolean `wiring.inertia`, `wiring.vite_alias` and `wiring.tailwind` values detected from the current files.

## Module routes

Module routes load only through `Mod::routes(only: [...], except: [...])`. Put the call in `withRouting(then: fn () => Mod::routes())` or inside a Laravel route group to inherit its middleware, URI prefix and name prefix. `config('mod.routes.order')` loads listed modules first, then the rest alphabetically; unknown names warn. Duplicate module calls are refused with both call locations. Cached routes make the call a no-op.

Use `mod:routes Inventory --api --console` for route files: web uses `web`, API uses `api` and `/api`, console loads only in the console. Use `mod:route-registrar Inventory` for a `RegistersRoutes` implementation with static `web()` and `api()` methods. Files load before registrars, and provider-loaded files are skipped. Both commands accept `--force` for replacement and `--dry-run --json` for a plan without writes. Refusals without a terminal name `--force`.

Scaffold inserts use `into: 'routes'` for the module's web file or `into: 'routes.web'` / `into: 'routes.api'` for registrar methods when present. Missing files start with the route anchor. Keep bindings and rate limiters in providers. `mod:list --json` reports the `routes` entries with `group`, `entrypoint`, `kind`, `middleware_group`, `order`, and `loaded_by`; listing never calls a registrar.
