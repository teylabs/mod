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
