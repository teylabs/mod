# Package routes

Register package route files from a service provider's `boot()`:

```php
Mod::routeFiles('billing', __DIR__.'/../routes');
// Or an autoloadable class implementing Tey\Mod\Routing\RegistersRoutes:
Mod::routeRegistrar('reports', ReportsRoutes::class);
```

Registration does not load routes. Keep `Mod::routes()` in the application's
routing callback, after package providers boot. Its existing `only:` and
`except:` arguments select package group names too. `mod.routes.order` places
named groups first, then the remaining groups alphabetically. Group names must
be unique across packages and layout groups. Register each group before any
`Mod::routes()` call that selects it.

A directory contributes `web.php`, `api.php` and `console.php`. HTTP files get
Laravel's `web` or `api` middleware; API routes get the `api` prefix. Console
files load only in console processes. Registrar classes contribute `web()` and
`api()`. Both forms inherit any surrounding route group:

```php
Route::middleware('auth')->prefix('admin')->group(
    fn () => Mod::routes(only: ['billing', 'reports']),
);
```

`mod:list --json` reports these entries under `routes`, with `kind: "package"`
and `loaded_by` provenance. Files already included by a provider are skipped.
Replace `loadRoutesFrom()` with registration to let mod control order and the
surrounding group. Duplicate loading calls remain errors. When routes are
cached, `Mod::routes()` returns without inspecting or loading registered files
or invoking registrars. Rebuild the route cache after changing registrations.
