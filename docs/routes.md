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
Laravel's `web` or `api` middleware; API routes default to the `api` prefix. Console
files load only in console processes. Registrar classes contribute `web()` and
`api()`. Both forms inherit any surrounding route group:

```php
Route::middleware('auth')->prefix('admin')->group(
    fn () => Mod::routes(only: ['billing', 'reports']),
);
```

## Choosing the API prefix

Pass `apiPrefix:` when registering files or a registrar to serve API routes
under `/v1` instead of `/api`:

```php
Mod::routeFiles('api-v1', __DIR__.'/../routes', apiPrefix: 'v1');
Mod::routeRegistrar('reports-v1', ReportsRoutes::class, apiPrefix: 'v1');
```

Or set the prefix for the selected groups in a loading call, including layout
groups:

```php
Mod::routes(only: ['Inventory', 'api-v1'], apiPrefix: 'v1');
```

The effective default is `'api'`, preserving existing behaviour. A registration
prefix wins over the call prefix, including an explicit `'api'` or `''`.
Omitting the registration argument (or passing `null`) inherits the call
prefix. Pass `apiPrefix: ''` to add no API prefix. Web and console entries are
unaffected, and any surrounding Laravel group still applies.

`mod:list` shows the effective prefix for each API entry, using `(none)` for
`''`. JSON adds `api_prefix` to API entries only; it reports the registration
prefix, the selected loading call's prefix, or `'api'` before a call selects
the group. The value excludes any surrounding Laravel group's prefix.

`mod:list --json` reports these entries under `routes`, with `kind: "package"`
and `loaded_by` provenance. Files already included by a provider are skipped.
Replace `loadRoutesFrom()` with registration to let mod control order and the
surrounding group. Duplicate loading calls remain errors. When routes are
cached, `Mod::routes()` returns without inspecting or loading registered files
or invoking registrars. Rebuild the route cache after changing registrations
or API prefixes.
