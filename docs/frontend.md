# Frontend wiring

## Full-page Inertia loads

Use `Mod::pageEntry()` in the root Blade view's Vite entries to map the current page to its real folder:

```blade
@vite(['resources/js/app.ts', \Tey\Mod\Facades\Mod::pageEntry($page['component'], 'vue')])
```

For React use the app's entry (such as `resources/js/app.tsx`) and `tsx` or `jsx` as its page extension. Ordinary app pages keep the app's `pages`/`Pages` folder; qualified pages follow the layout's frontend paths. The helper returns a project-relative manifest key and refuses unknown namespaces or traversal. Build the frontend after adding pages. The module page glob in the app entry includes these files in Vite's manifest. This supports direct loads as well as client navigation.

## Customized Inertia bootstraps

Run `php artisan mod:install inertia --dry-run` first. Known starter-kit anchors can be wired automatically. Existing wiring is recognised without edits: a vendor `resolveModulePage` import and call inside `resolve`, including multiline callbacks and array globs with `{ import: 'default' }`; an `@modules` alias using `path.resolve(...)` or `fileURLToPath(new URL(...))` with the module views refresh path; and the exact Tailwind `@source` before or after `@import`. A fully wired app reports `Already wired.`. If a custom shape cannot be confirmed, the installer says so and asks you to check the expected wiring. If the bootstrap has a custom resolver, the installer prints the import and exact page lookup for the configured paths, names the entry file and insertion point, and prints the Tailwind sources. JSON previews include the same instructions in `warnings`. It applies no edits while manual wiring is required.

For a modal adapter or persistent default layout, keep the existing adapter import, plugins and setup callback. Add the resolver import beside the other imports in `resources/js/app.ts`:

```ts
import { resolveModulePage } from '../../vendor/tey/mod/resources/js/inertia';
```

Replace only the page lookup inside the existing `resolve` callback. For the default Vue module paths:

```ts
type PageModule = {
    default: import('vue').DefineComponent & { layout?: typeof DefaultLayout };
};

// Inside the existing createInertiaApp options:
resolve: async (name) => {
    const page = await resolveModulePage(
        name,
        import.meta.glob<PageModule>('./pages/**/*.vue'),
        import.meta.glob<PageModule>('../../app/Modules/*/resources/js/pages/**/*.vue'),
    );
    page.default.layout ??= DefaultLayout;
    return page;
},
```

Keep the application's existing `DefaultLayout` import. A modal package's `createInertiaApp` wrapper can use this same lookup; retain its setup code. React uses the same resolver with `.tsx`/`.jsx` globs and the application's existing layout wrapper. Use the exact globs the installer prints for custom roots, `Pages` casing and JavaScript entries.

For Tailwind v4, add the printed `@source` after the Tailwind import in `resources/css/app.css`:

```css
@source '../../app/Modules/**/resources/**/*.{vue,ts,blade.php}';
```

For Tailwind v3, add the printed project-relative glob to the `content` array in the app's Tailwind config. Apply the Vite alias and TypeScript additions printed for their own files too. Then build the app and check a direct module page load.


### Read the frontend stack

Packages can call `Mod::stack()` from their provider. Its `inertia()` returns
`vue`, `react`, `svelte` or `null`; `typescript()` checks for `tsconfig.json`,
`pagesPath()` preserves the app's `pages`/`Pages` casing, and `livewire()` detects
`livewire/livewire` or `livewire/flux` in Composer declarations. `toArray()` is the
same `stack` record returned by `mod:list --json`. This reads declarations without
installing dependencies or changing files. Svelte pages use `.svelte`; an app's
`stubs/mod.page.svelte.stub` overrides the minimal default.


### Package pages and wiring

Register package pages from a provider:

```php
Mod::pages('billing', __DIR__.'/../resources/js/pages');
Mod::wiring()
    ->alias('@acme/billing', 'vendor/acme/billing/resources/js')
    ->source('vendor/acme/billing/resources/js');
```

Render `billing::Invoices` normally. Both Inertia page finders and
`Mod::pageEntry('billing::Invoices', 'vue')` use the declared directory.
Namespaces must be unique and page directories must exist inside the application,
including after resolving symlinks. External finder hints retain precedence.

Run `mod:install inertia --dry-run --json` to preview literal package page globs,
aliases, TypeScript paths/includes and Tailwind sources. Apply with
`mod:install inertia --no-interaction`. Repeated installation says "Already
wired." when every declaration is confirmed. Custom bootstraps receive the exact
manual lines; keep adapters, layout assignment and plugins intact. Adding a new
package declaration requires another installation; new files under existing
globs do not. Providers only declare wiring; they never write application files.

A package command can use `$plan = Mod::wiring()->plan()`, inspect
`$plan->toArray()` and explicitly call `$plan->apply()`. Manual wiring warnings prevent writing,
and stale previews are refused before any file is written. `source()` scans
Tailwind classes without loading CSS. `css()` explicitly imports the chosen
stylesheet into `resources/css/app.css`; style order remains application-owned.
Aliases are ordered most specific first. TypeScript paths include both the bare
alias (`"@acme/billing": ["./vendor/acme/billing/resources/js"]`) and its subpaths
(`"@acme/billing/*": ["./vendor/acme/billing/resources/js/*"]`). The bare alias
uses TypeScript's normal directory index resolution; there is no `index:` option.
An existing conflicting TypeScript mapping requires a manual edit.

Composer path repositories installed as symlinks (or Windows directory junctions) are supported. Installed paths
under `vendor/` may resolve outside the application. A provider's absolute
`__DIR__` path is mapped back through the installed package symlink, so all page
entries, globs and planned edits keep their lexical `vendor/...` paths.
Other external paths, traversal and symlink escapes are skipped, as are missing
page directories, invalid names and conflicting declarations. They never stop
application boot. Wiring paths may name directories that will be created later.

The JSON inventory adds `package_pages`, `frontend_wiring` and
`frontend_notices`. Each notice has `code`, `path` and `message`. Stable codes are
`frontend_path_invalid`, `frontend_path_escape`, `frontend_path_unavailable`,
`frontend_alias_invalid`, `frontend_alias_conflict`, `page_namespace_invalid`
and `page_namespace_conflict`. `mod:install` prints these notices; JSON previews
include them in `warnings` with the same code and `data.path`. Skipped declarations
do not prevent valid declarations from being installed.


### Frontend files in mounted roots

A root can own a component-only kit without becoming a module:

```php
Mod::layout('custom')->extends('modules')->path('app/Modules/{module}')
    ->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root
        ->frontend(components: 'resources/js', alias: '@/UI'));
```

All `Root::frontend()` paths are relative to the mount. Pages opt in with both
`pages: 'screens'` and `pageName: 'UI::{path}'`; views opt in with both
`views: 'templates'` and `viewNamespace: 'ui'`. Names and aliases are explicit;
conflicts are rejected before installation. Existing layout-level frontend paths
retain their project-relative semantics. Omitted arguments preserve declarations,
and child layouts inherit their parent's owners.

Run the normal installation preview/apply step. The owner adds a specific Vite
alias before broader aliases, TypeScript paths/includes, Tailwind sources and
canonical page globs. Component files are dependencies, never executable inputs.
Views use Laravel's namespace/anonymous-component registration and its reserved
namespace protection. CSS imports, global component registration, layouts,
framework plugins and exclusions remain application-owned. `frontend_owners` in
`mod:list --json` exposes the resolved paths and identities.


### Static assets and executable entries

Declare narrow static patterns separately from styles or scripts that need
compilation:

```php
Mod::layout('modules')->assets(
    files: ['app/Modules/{module}/images/**', 'app/Modules/{module}/fonts/**'],
    entries: ['app/Modules/{module}/styles/editor.css'],
);
// Inside a mount: $root->assets(files: ['images/**'], entries: ['css/theme.css']);
```

Layout paths are project-relative; root paths are mount-relative. Static globs
support `*` and `**` plus declared layout dimensions. Entries are literal CSS/JS/TS
file templates expanded across existing owners; absent optional files are omitted
from inputs and reported in the inventory. Traversal, external paths, malformed
patterns, raw style/script file declarations and escaping symlinks are refused
before writing. Ordinary imports and CSS `url()`/`@import` dependencies continue
to use Vite's normal graph.

Preview with `mod:install assets --dry-run --json`, then apply with
`mod:install assets --no-interaction`. No Inertia stack is required. Automatic
static wiring requires `laravel-vite-plugin` 3; older versions retain ordinary
imports and receive manual instructions until an adapter is verified. Custom
Vite options receive exact instructions. Existing central inputs, static patterns
and plugin options are retained.

The installer owns `resources/mod-assets.json` and imports it into the Vite
configuration. Do not edit that generated file; change declarations and sync.
Adding static files beneath existing globs needs no sync. Adding or removing
independent entries requires another explicit installation before building.
Repeated sync is a no-op, and removed owned entries disappear from the generated
list. Unrelated application inputs remain intact.

Reference images/fonts from JS with imports, or from Blade with
`Vite::asset('app/Modules/Editor/images/logo.svg')`. Select independent stylesheet
loading with `@vite('app/Modules/Editor/styles/editor.css')`. Building that entry
does not load it globally. Use Laravel's usual build-directory/CDN configuration.
Declaring images/fonts neither imports CSS nor adds Tailwind sources. CSS themes,
class sources, import order and loading remain application-owned. The additive
`assets` inventory records declarations, expanded entries, existence and confirmed
wiring; it makes no claim that a production build exists.

The supported build fixture can be verified with `npm ci --prefix
 tests/Fixtures/frontend-build --ignore-scripts` and
`MOD_FRONTEND_BUILD=1 vendor/bin/pest tests/Feature/Install/FrontendBuildTest.php`.
It checks alias resolution, TypeScript, Tailwind, static manifest keys, nested CSS
references, development serving and Laravel references with a custom build path.
