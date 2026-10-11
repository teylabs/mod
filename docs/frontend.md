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
`$plan->toArray()` and explicitly call `$plan->apply()`. Warnings prevent writing,
and stale previews are refused before any file is written. `source()` scans
Tailwind classes without loading CSS. `css()` explicitly imports the chosen
stylesheet into `resources/css/app.css`; style order remains application-owned.
Alias declarations reject conflicting targets and are ordered most specific
first. The JSON inventory adds `package_pages` and `frontend_wiring` records.
