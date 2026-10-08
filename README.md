# Mod: layout-aware generators and discovery for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3Arun-tests+branch%3Amain)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/teylabs/mod/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)
[![License](https://img.shields.io/packagist/l/tey/mod.svg?style=flat-square)](LICENSE.md)

Mod lets a Laravel application declare how its code is organised (ordinary Laravel, feature folders, vertical slices, type-first, `app/Modules/<Module>`, or a layout of your own) and then works within that layout:

- **Generation.** `mod:*` commands place files where your layout says, using Laravel's own `make:*` generators, so the generated code is exactly what Laravel would write.
- **Relations.** Related artifacts land in the right place too: a model's factory, a controller's form requests, a listener's event.
- **Discovery.** Service providers, Artisan commands, event listeners and subscribers anywhere in your layout are found and registered, with a cache for production.

> Mod is pre-1.0: minor releases may still change the API. The public API is what this README documents; classes and methods marked `@internal` are not part of it.

## Requirements

- PHP 8.3+
- Laravel 12 or 13


## Installation

```bash
composer require tey/mod
```

Publish the configuration file to choose a layout:

```bash
php artisan vendor:publish --tag=mod-config
```

## Choosing a layout

`config/mod.php` names the active layout. Five layouts are built in:

```php
'layout' => 'modules',
```

| Layout | Placement | Example: `mod:model Billing:Invoice` |
| --- | --- | --- |
| `laravel` (default) | exactly like `make:*` | `mod:model Invoice` → `app/Models/Invoice.php` |
| `features` | `app/Features/<Feature>/...` | `app/Features/Billing/Models/Invoice.php` |
| `slices` | `app/<Feature>/<Slice>/...` (fixed basenames such as `Handler`) | `app/Billing/Models/Invoice.php` |
| `type-first` | `app/Models/<Feature?>/...` (the feature folder is optional) | `app/Models/Billing/Invoice.php` |
| `modules` | `app/Modules/<Module>/...` (flat folders, no `Http/`) | `app/Modules/Billing/Models/Invoice.php` |

All five layouts declare the common native generator kinds, including events, listeners and tests. Tests live under `tests/Feature/<placement>` (`--unit` uses `tests/Unit/<placement>`); config files are declared only by `laravel` and `type-first`, when the native generator is available. Components and views are held to v1. `php artisan list mod` shows the commands your layout provides.

Commands in `features` and `slices` belong to a feature when placement is given and use `app/Console/Commands` when it is omitted. Other kinds still require their declared placement. Slice requests have one fixed `Request` per operation; request companions generate that single request, with no update-request relation. Modules do not declare a routes kind.

## Generating

Every kind in the layout gets a `mod:<kind>` command, a thin subclass of the matching `make:*` command. The arguments and options are Laravel's own. The only addition is where the file goes:

```bash
php artisan mod:model Billing:Invoice --factory
php artisan mod:controller Billing:InvoiceController
php artisan mod:migration Billing:create_invoices_table --create=invoices
```

- `Billing:Invoice` is shorthand for `Invoice --in=Billing`. The text before the first colon is the placement, and the rest is the class name. Giving both the prefix and `--in` is refused, as is a prefix on a layout without placement groups.
- With two placeholders (`slices`: `{feature}/{slice}`), separate the values with `/`: `--in=Billing/CreateInvoice`.
- Companion options such as `--factory` and `--migration` follow the layout's relations, in every built-in layout. Under `modules`, `mod:model Billing:Invoice --factory --migration` writes `app/Modules/Billing/Database/Factories/InvoiceFactory.php`, with the model's `newFactory()` pointing at it, and a migration in `app/Modules/Billing/Database/Migrations/`.
- Migrations a layout places outside `database/migrations` are added to the migrator, so `php artisan migrate` runs them (see Discovery).
- A companion option the layout declares no relation for is refused before anything is written. So is a file that already exists, or a placement value the layout does not use.
- Every error mod raises extends `Tey\Mod\Exceptions\ModException`.
- `make:*` is untouched and keeps writing to Laravel's default locations.

## Defining or extending a layout

Define a layout as one fluent chain in any service provider, for example in `AppServiceProvider::boot()`, then select it by name in `config/mod.php`:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

Mod::layout('ddd')
    ->root('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r
        ->kind('model', in: '{domain}/Models')
        ->kind('action', in: '{domain}/Actions'))
    ->root('app', 'App\\', 'app', fn (Root $r) => $r
        ->kind('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
    ->kind('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
    ->relation('factory', from: 'model', to: 'factory')
    ->exclude('App\\Support\\');
```

```php
'layout' => 'ddd',
```

`php artisan mod:model Billing:Invoice --factory` now writes `src/Domain/Billing/Models/Invoice.php` and `src/Domain/Billing/Database/Factories/InvoiceFactory.php`. A `Domain\\` entry in your `composer.json` autoload is up to you.

Calling `Mod::layout()` with an existing name extends that layout. Repeating a kind, root or relation id overrides only the arguments you pass:

```php
Mod::layout('features')
    ->kind('event', in: 'Features/{feature}/Events')
    ->kind('listener', in: 'Features/{feature}/Listeners');

Mod::layout('laravel')->kind('job', in: 'Jobs');
```

The building blocks:

- `root($name, $namespace, $path, $closure)` maps a namespace to a folder. Kinds declared inside the closure live in that root. A `null` namespace makes a root for plain files.
- `kind($id, in: ...)` declares an artifact kind and its folder below the root. A `root:` prefix (`domain:{domain}/...`) places it in another root. Without one, the kind uses the enclosing `root()` closure's root, or else the first declared root.
- Other `kind()` arguments:
  - `suffix:` (`'Controller'`);
  - `fixed:` (a fixed basename such as `'Handler'`);
  - `timestamped: true` (migrations);
  - `nested: true` (accepts names like `Archived/Invoice`, the same as `make:model Archived/Invoice`);
  - `command:` (defaults to `mod:<id>`; `false` for none);
  - `discoverAnywhere: true` with `except: [...]` (see Discovery).
- `relation($id, from: ..., to: ...)` drives companion options and cross-references, and also takes `scope:`, `name:` and `policy:` (`'generate'`, `'reference'` or `'none'`).
  - `name:` says how the target's name derives from the source's: `'explicit'` (always named by the caller) or a map of `strip-suffix`, `prefix` and `suffix`, for example `['prefix' => 'Store']`. The target kind's own `suffix:` or `fixed:` still applies afterwards.
  - `scope: ['nested' => 'drop']` stops the source's nested folders carrying over to the target. By default they do: `Models/Archived/Invoice` relates to `Policies/Archived/InvoicePolicy`.
- `exclude(...)` marks namespaces or paths inside a declared root that no kind owns: mod never places anything there, does not treat their classes as part of the layout, and discovery skips them.
- `withoutCommands()` registers no `mod:*` commands for the layout. Its kinds keep their `command:` names for a host to dispatch by, and several kinds may then share one.

A kind with no matching Laravel generator (`action` above) gets a plain class. Put a `stubs/mod.<kind>.stub` in your application to change it.

The active layout is checked the first time it is used. Every problem is reported at once, each naming the call that caused it.

### Placeholders

Placeholders in `in:` are the layout's placement values, in order of first appearance. That order is the order of values in `--in`.

| Placeholder | Meaning | `--in` |
| --- | --- | --- |
| `{feature}` | required folder | `--in=Billing` |
| `{feature?}` | optional folder | omit it, or `--in=Billing` |
| `{group+}` | one or more folders | `--in=Billing.Invoicing` places `.../Billing/Invoicing/...` |

## Discovery

Mod discovers the providers, Artisan commands, event listeners and event subscribers your layout places, and registers them with Laravel after every provider has booted. Kinds with the id `provider`, `command`, `listener` or `subscriber` are discovered as that type. Map other kinds in `config/mod.php`:

```php
'discovery' => [
    'enabled' => true,
    'kinds' => ['console' => 'command'],
    'cache' => 'bootstrap/cache/mod-discovery.php',
    'on_stale_cache' => 'scan',
],
```

- Only classes that really are providers, commands, listeners or subscribers are registered. Everything else is skipped.
- A kind is discovered in its own folder by default. `discoverAnywhere: true` widens that to every PHP file below the kind's placeholder folders, and `except: ['Tests']` skips folders. Kinds placed by a callback (`using: fn (Kind $k) => $k->place(...)`) are discovered in their own folder only.
- Listeners and subscribers are registered once, never twice. Whatever Laravel's own event discovery covers (`app/Listeners`, or the paths given to `withEvents()`), its events cache, or a manual `Event::listen()` already holds is left alone.
- A `subscriber` is a class with a public `subscribe()` taking one parameter, registered through `Event::subscribe()`.
- Migrations: the folders a timestamped kind such as `migration` places files in are added to Laravel's migrator, so `php artisan migrate` picks them up. Laravel's own `database/migrations` is left to Laravel. To manage them yourself, opt out with `'kinds' => ['migration' => false]`; `Inventory::directories('migration')` still lists the folders.

### Caching

```bash
php artisan mod:discovery-cache   # also run by php artisan optimize
php artisan mod:discovery-clear   # also run by php artisan optimize:clear
```

With a cache present, mod registers from the cache without scanning. A stale cache (the layout or the discovery settings changed since it was written) is ignored: mod scans instead and logs a warning naming `mod:discovery-cache` and `mod:discovery-clear`. Set `'on_stale_cache' => 'fail'` to refuse to boot instead.

Like Laravel's own caches, the discovery cache does not notice new classes. After adding a provider, command or listener while the cache exists, run `php artisan optimize:clear` (or `mod:discovery-clear`).

### Bring your own candidate files

A package can supply the files discovery considers, for example to reuse an existing finder or skip generated folders. Ownership, eligibility, ordering and registration stay with mod. Turn the built-in registration off (`'discovery.enabled' => false`) and register discovery yourself once every provider has booted:

```php
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Placement\Root;
use Tey\Mod\Preset\Preset;

$this->app->booted(function ($app) {
    $options = DiscoveryOptions::fromConfig([...config('mod.discovery'), 'enabled' => true])
        ->withCandidates(fn (Root $root, string $basePath, DiscoveryDefinition $definition): iterable => [
            // relative .php paths below $root->path
        ]);

    DiscoveryRegistrar::register($app, $app->make(Preset::class), $options);
});
```

The definition tells the source which kind (and discovery type) it is collecting for, so candidates can be scoped per kind.

## Turning commands off

A package or application with its own Artisan commands can keep mod's placement and discovery without the `mod:*` commands:

```php
// config/mod.php
'commands' => false,
```

or per layout with `Mod::layout('mine')->withoutCommands()`. The discovery cache commands are registered only while `mod:*` commands and discovery are both on.

## Building your own generators

Every `mod:*` command is a subclass of the matching Laravel command, for example `Tey\Mod\Commands\ModelCommand`, `ControllerCommand`, `RequestCommand`, `FactoryCommand` and `MigrationCommand`. A package with its own command catalog can extend these instead of the native commands and override a few protected hooks:

- **Placement:**
  - `placementInput()` returns the placement in `--in` syntax, for example from your own option or prompt;
  - `placementContext()`;
  - `placementOptionName()` returns `null` to add no `--in` option, in which case child commands receive the `Group:Name` form.
- **Layout and kind:** `resolvePreset()` and `kindId()`, for commands not registered through the layout.
- **Collisions:** `collisionPolicy()` returns `CollisionPolicy::Refuse` (mod checks the whole plan before writing) or `CollisionPolicy::Native` (the native generator's own check and `--force` decide).
- **Lifecycle:**
  - `plansEagerly()`, with `resolvePlan()` to plan from inside your own `handle()`;
  - `beforeGeneration(GenerationPlan $plan)` and `afterGeneration(GenerationPlan $plan, int $exitCode)`;
  - the same hooks exist on `MigrationCommand`, whose `nativePathAllowed()` also lets `--path`/`--realpath` through instead of refusing them.
- **Output:** `reportRefusal(ModException $e)` and `reportReference(ResolvedArtifact $target)` are the only places the adapters print on their own.

## Testing

```bash
composer test
composer analyse
composer lint
```

## Changelog

See [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/teylabs/mod). Questions and ideas go to [Discussions](https://github.com/teylabs/mod/discussions).

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jasper Tey](https://github.com/jaspertey)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
