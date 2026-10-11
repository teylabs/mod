# Frontend wiring

## Full-page Inertia loads

Use `Mod::pageEntry()` in the root Blade view's Vite entries to map the current page to its real folder:

```blade
@vite(['resources/js/app.ts', \Tey\Mod\Facades\Mod::pageEntry($page['component'], 'vue')])
```

For React use the app's entry (such as `resources/js/app.tsx`) and `tsx` or `jsx` as its page extension. Ordinary app pages keep the app's `pages`/`Pages` folder; qualified pages follow the layout's frontend paths. The helper returns a project-relative manifest key and refuses unknown namespaces or traversal. Build the frontend after adding pages. The module page glob in the app entry includes these files in Vite's manifest. This supports direct loads as well as client navigation.
