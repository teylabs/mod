# Module database connections

Declare a connection in a service provider's `register()` or `boot()`, before
mod runs discovery. Use a complete layout group name (`Catalogue` in modules,
`Catalogue` as a domain in ddd, or every dimension of a custom layout):

```php
Mod::connection('Catalogue', 'catalogue');
```

Configure `database.connections.catalogue` through Laravel as usual. Declarations
are application scoped and consistent repeated declarations are allowed. An
unknown connection, incomplete group, or conflicting declaration is refused.
`mod:list --json` adds `connections`: each row contains `group`, `connection`
and `declared`. Undeclared groups use Laravel's default connection.

## Models and factories

Use `Tey\Mod\Database\UsesGroupConnection` on existing models, or on their shared
base model (or extend `Tey\Mod\Database\ConnectedModel`). Laravel calls its initializer on every new instance; mod maps the
concrete class through the active layout to find its group. No connection name
is repeated on individual models:

```php
class Item extends Model
{
    use UsesGroupConnection;
    use HasFactory;
}
```

Factories inherit their model's connection. A model's explicit `$connection`,
`setConnection()`, `Model::on()`, or a factory's `connection()` remains the
application's choice. Add the trait to published model stubs if newly generated
models should adopt this behaviour. Existing models are not rewritten.

## Seeders

Use `Tey\Mod\Database\SeedsGroupConnection` on a Laravel seeder or shared seeder
base (or extend `Tey\Mod\Database\ConnectedSeeder`). Laravel's normal `__invoke()` execution selects the seeder's layout group
connection for `DB` calls and child seeders, then restores the previous default,
including when seeding throws. Calling `run()` directly bypasses this wrapper.
Explicit `DB::connection()` calls still choose their own connection. Models
without `UsesGroupConnection` follow the scoped default while seeding.

## Migrations

With discovery enabled, plain `migrate` includes undeclared groups and groups on
the application's default connection. It leaves separately connected groups
out. `migrate --database=catalogue` selects the discovered migration directories
of groups declared on `catalogue`, excluding the application's ordinary
`database/migrations`. Laravel records them in that connection's `migrations`
table. Rollback, reset, refresh, fresh and status use the same selection,
including nested command calls. Other connections retain Laravel's ordinary
path selection. Explicit custom `--path` options still select those paths.

`mod:migration Catalogue:create_items_table` uses the normal layout directory
and writes the module's `$connection` into Laravel's migration stub. Custom
stubs must extend the imported `Migration` class. A host-provided migration
creator keeps control of its output. Declare connections before discovery and
before resolving the migrator; applications that replace Laravel's migrator
must supply their own connection selection and cannot use these declarations.

For a separately rebuilt SQLite dataset:

```sh
php artisan migrate:fresh --database=catalogue --force
php artisan db:seed --database=catalogue --class='App\Modules\Catalogue\Database\Seeders\CatalogueSeeder' --force
```

Laravel's `migrate:fresh` drops tables on the selected database. Keep this
connection separate from the application's deployment database. A discovery
cache stores directories, while providers supply current connection declarations
on each boot; rebuild the discovery cache after moving migration directories.
