# Mod: Modular Development Toolkit for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)

Mod is a lightweight toolkit for modular development in Laravel.

Organizing an app by module, feature or domain usually means fighting Laravel's defaults: `make:*` writes to `app/Models`, and every module's providers, commands and listeners need registering by hand. Mod makes Laravel's own tools work in the structure you choose. Pick or extend a common layout like DDD or a modular monolith, or create your own.

Created by [Jasper Tey](https://github.com/jaspertey), building on the lessons from [laravel-ddd](https://github.com/teylabs/laravel-ddd) and generalized for the many other ways developers organize their growing Laravel applications.

```bash
php artisan mod:model Billing:Invoice -mf   # with 'layout' => 'modules'
```

```text
app/Modules/Billing/
├── Database/
│   ├── Factories/
│   │   └── InvoiceFactory.php
│   └── Migrations/
│       └── 2026_10_08_120000_create_invoices_table.php
└── Models/
    └── Invoice.php
```

`php artisan migrate` runs that migration, and `Invoice::factory()` finds that factory.

> [!NOTE]
> Mod is pre-1.0. Minor releases may change the API until 1.0.

- [Six built-in layouts](#choosing-a-layout), including modular monolith and DDD
- [Laravel's own generators](#generating) for every file type, writing into your layout
- [Related files follow](#related-files): a model's factory, migration, policy and form requests land beside it
- [Auto-discovery](#auto-discovery) of providers, commands, listeners, migrations, factories and policies
- [Your own file types](#your-own-file-types) in one line

## Installation

Mod requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require tey/mod
php artisan vendor:publish --tag=mod-config
```

## Quick Start

Choose a layout in `config/mod.php`:

```php
// config/mod.php
'layout' => 'modules',
```

Generate a model with its migration and factory, then migrate:

```bash
php artisan mod:model Billing:Invoice -mf
# -> app/Modules/Billing/Models/Invoice.php
# -> app/Modules/Billing/Database/Factories/InvoiceFactory.php
# -> app/Modules/Billing/Database/Migrations/2026_10_08_120000_create_invoices_table.php

php artisan migrate
# -> runs 2026_10_08_120000_create_invoices_table
```

## Usage

### Choosing a Layout

Six layouts are built in. The default, `laravel`, places files exactly like `make:*`, so you can install mod first and switch layouts later.

| Layout | Organizes code as | `mod:model Billing:Invoice` writes |
| --- | --- | --- |
| `laravel` | Laravel's own folders | `app/Models/Invoice.php` (no `Billing:`) |
| `modules` | a modular monolith: one folder per module | `app/Modules/Billing/Models/Invoice.php` |
| `features` | feature folders | `app/Features/Billing/Models/Invoice.php` |
| `slices` | vertical slices: features, each split into slices | `app/Billing/Models/Invoice.php` |
| `type-first` | Laravel's folders, with an optional sub-folder | `app/Models/Billing/Invoice.php` |
| `ddd` | domain-driven design, as in laravel-ddd | `src/Domain/Billing/Models/Invoice.php` |

Each tree below is the result of `php artisan mod:model Billing:Invoice --all` in a fresh app.

<details>
<summary><code>modules</code></summary>

```text
app/Modules/Billing/
├── Controllers/
│   └── InvoiceController.php
├── Database/
│   ├── Factories/
│   │   └── InvoiceFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_invoices_table.php
│   └── Seeders/
│       └── InvoiceSeeder.php
├── Models/
│   └── Invoice.php
├── Policies/
│   └── InvoicePolicy.php
└── Requests/
    ├── StoreInvoiceRequest.php
    └── UpdateInvoiceRequest.php
```

</details>

<details>
<summary><code>features</code></summary>

```text
app/Features/Billing/
├── Database/
│   ├── Factories/
│   │   └── InvoiceFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_invoices_table.php
│   └── Seeders/
│       └── InvoiceSeeder.php
├── Http/
│   ├── Controllers/
│   │   └── InvoiceController.php
│   └── Requests/
│       ├── StoreInvoiceRequest.php
│       └── UpdateInvoiceRequest.php
├── Models/
│   └── Invoice.php
└── Policies/
    └── InvoicePolicy.php
```

</details>

<details>
<summary><code>slices</code></summary>

A slice holds one operation's classes, each with a fixed name. This tree is the result of `mod:model Billing:Invoice -mf`, then `mod:handler`, `mod:request` and `mod:message` with `--in=Billing/CreateInvoice`:

```text
app/Billing/
├── CreateInvoice/
│   ├── Command.php
│   ├── Handler.php
│   └── Request.php
├── Database/
│   ├── Factories/
│   │   └── InvoiceFactory.php
│   └── Migrations/
│       └── 2026_10_08_120000_create_invoices_table.php
└── Models/
    └── Invoice.php
```

</details>

<details>
<summary><code>type-first</code></summary>

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── Billing/
│   │       └── InvoiceController.php
│   └── Requests/
│       └── Billing/
│           ├── StoreInvoiceRequest.php
│           └── UpdateInvoiceRequest.php
├── Models/
│   └── Billing/
│       └── Invoice.php
└── Policies/
    └── Billing/
        └── InvoicePolicy.php
database/
├── factories/
│   └── Billing/
│       └── InvoiceFactory.php
├── migrations/
│   └── Billing/
│       └── 2026_10_08_120000_create_invoices_table.php
└── seeders/
    └── Billing/
        └── InvoiceSeeder.php
```

</details>

<details>
<summary><code>ddd</code></summary>

```text
app/Modules/Billing/
├── Controllers/
│   └── InvoiceController.php
└── Requests/
    ├── StoreInvoiceRequest.php
    └── UpdateInvoiceRequest.php
src/Domain/Billing/
├── Database/
│   ├── Factories/
│   │   └── InvoiceFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_invoices_table.php
│   └── Seeders/
│       └── InvoiceSeeder.php
├── Models/
│   └── Invoice.php
└── Policies/
    └── InvoicePolicy.php
```

</details>

[docs/layouts.md](docs/layouts.md) lists every folder of every built-in layout.

### Generating

Each file type in your layout has a `mod:*` command. It is Laravel's own `make:*` command underneath, with the same arguments and options, so the generated code is what Laravel would write:

```bash
php artisan mod:event Billing:InvoicePaid
# -> app/Modules/Billing/Events/InvoicePaid.php

php artisan mod:listener Billing:SendInvoiceReceipt --event=InvoicePaid
# -> app/Modules/Billing/Listeners/SendInvoiceReceipt.php (imports App\Modules\Billing\Events\InvoicePaid)

php artisan mod:job Billing:SendInvoice
# -> app/Modules/Billing/Jobs/SendInvoice.php
```

`php artisan list mod` shows every command your layout has. `make:*` is untouched and keeps writing to Laravel's default folders.

#### Related Files

Options such as `-m`, `-f`, `--policy`, `--requests` and `--all` create the related files in the same module, as in the trees above. The model links its factory, so `Invoice::factory()` works wherever the factory lives.

`mod:*` checks every file it is about to write before writing any of them. When one already exists, it prints an error and writes nothing.

### Placement

The `laravel` layout puts files where `make:*` does. The other layouts group your code, so each command also needs to know which group a file belongs to.

Each way a layout groups code is a **dimension**. `modules` has one: the module. `slices` has two: the feature, and the slice inside it. A layout's folders show each one as a placeholder, such as `{module}` in `app/Modules/{module}/Models`, and you give it a value, such as `Billing`. These three commands do the same thing:

```bash
php artisan mod:model Invoice --module=Billing   # an option named after the placeholder
php artisan mod:model Invoice --in=Billing       # every value at once
php artisan mod:model Billing:Invoice            # the short form: value, colon, class name
```

When there are two values, `--in` and the short form take them in order, separated by `/`:

```bash
php artisan mod:handler Handler --feature=Billing --slice=CreateInvoice
php artisan mod:handler Handler --in=Billing/CreateInvoice
# -> app/Billing/CreateInvoice/Handler.php
```

| Layout | Options | Values |
| --- | --- | --- |
| `modules` | `--module` | `Billing` |
| `features` | `--feature` | `Billing` |
| `slices` | `--feature`, `--slice` | `Billing`, `CreateInvoice` |
| `type-first` | `--feature` (optional) | `Billing`, or none for `app/Models/Invoice.php` |
| `ddd` | `--domain` (one or more folders) | `Billing`, or `Reporting.Internal` for `src/Domain/Reporting/Internal` |

Commands in `features` and `slices` go to `app/Console/Commands` when you leave the value out.

### The DDD Layout

The `ddd` layout uses [laravel-ddd](https://github.com/teylabs/laravel-ddd)'s folders: domain classes in `src/Domain`, and controllers, requests and middleware in `app/Modules`. Add the `Domain` namespace to your `composer.json` autoload first:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Domain\\": "src/Domain/"
    }
}
```

```bash
composer dump-autoload

php artisan mod:dto Billing:InvoiceData
# -> src/Domain/Shared/Data/DataTransferObject.php (created once)
# -> src/Domain/Billing/Data/InvoiceData.php

php artisan mod:action Billing:PayInvoice
# -> src/Domain/Billing/Actions/PayInvoice.php

php artisan mod:value Billing:Money
# -> src/Domain/Billing/ValueObjects/Money.php
```

`mod:view-model` completes the set, and laravel-ddd's command names work as aliases (`mod:data`, `mod:value-object`, `mod:viewmodel`). See [docs/layouts.md](docs/layouts.md#the-ddd-layout) for every folder and for adding a layer such as `src/Infrastructure`.

### Starter Stubs and Stub Variants

DTOs, value objects, view models and actions start as plain Laravel-style classes. When a package for them is installed, mod uses it instead:

| Command | When installed | Otherwise |
| --- | --- | --- |
| `mod:dto` | [spatie/laravel-data](https://github.com/spatie/laravel-data): extends `Data` | extends a `DataTransferObject` base with `fromArray()` and `toArray()` |
| `mod:view-model` | [spatie/laravel-view-models](https://github.com/spatie/laravel-view-models): extends `ViewModel` | extends a `ViewModel` base |
| `mod:action` | [lorisleiva/laravel-actions](https://github.com/lorisleiva/laravel-actions): `use AsAction;` | a plain class with `handle()` |
| `mod:value` | | a plain class with a constructor |

```bash
php artisan mod:dto Billing:InvoiceData
# ->  INFO  Using spatie/laravel-data (installed).
```

A base class is written into your app the first time it is needed, and it is yours from then on: mod never overwrites it. To extend a class of your own instead, set it in `config/mod.php` (`'layouts' => ['ddd' => ['bases' => ['dto' => App\Support\Data::class]]]`). To change any generated class, publish `stubs/mod.<type>.stub`, for example `stubs/mod.dto.stub`.

### Auto-Discovery

Providers, Artisan commands, event listeners and event subscribers anywhere your layout places them are registered with Laravel:

```bash
php artisan mod:command Billing:SendReminders
php artisan mod:listener Billing:SendInvoiceReceipt --event=InvoicePaid

php artisan event:list --event=InvoicePaid
# -> App\Modules\Billing\Events\InvoicePaid
# ->   ⇂ App\Modules\Billing\Listeners\SendInvoiceReceipt@handle
```

A listener Laravel's own event discovery already registers is never registered twice. [docs/discovery.md](docs/discovery.md) covers what is discovered where.

#### Migrations

Migration folders outside `database/migrations`, such as `app/Modules/Billing/Database/Migrations`, are added to Laravel's migrator. `php artisan migrate`, `migrate:rollback` and `migrate:status` include them.

#### Factories and Policies

A model the layout places finds its factory and its policy by the layout's folders, with no registration:

```php
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\Gate;

Invoice::factory();                 // App\Modules\Billing\Database\Factories\InvoiceFactory
Gate::getPolicyFor(Invoice::class); // App\Modules\Billing\Policies\InvoicePolicy
```

### Your Own File Types

Add a file type to any layout with one line in a service provider:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    Mod::layout('modules')->kind('validator', in: 'Modules/{module}/Validators', suffix: 'Validator');
}
```

```bash
php artisan mod:validator Billing:Payment
# -> app/Modules/Billing/Validators/PaymentValidator.php
```

It starts as an empty class. To start from your own stub, add `stubs/mod.validator.stub` to your app:

```php
// stubs/mod.validator.stub
<?php

namespace {{ namespace }};

class {{ class }}
{
    public function rules(): array
    {
        return [];
    }
}
```

### Defining a Layout

A layout of your own is one chain in a service provider. Name it in `config/mod.php` with `'layout' => 'domains'`:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

public function boot(): void
{
    Mod::layout('domains')
        ->root('domain', 'Domain\\', 'src/Domain', fn (Root $root) => $root
            ->kind('model', in: '{domain}/Models')
            ->kind('factory', in: '{domain}/Factories', suffix: 'Factory'))
        ->relation('factory', from: 'model', to: 'factory');
}
```

```bash
php artisan mod:model Billing:Invoice --factory   # after adding Domain\ to composer.json, as in the DDD layout
# -> src/Domain/Billing/Models/Invoice.php
# -> src/Domain/Billing/Factories/InvoiceFactory.php
```

`Mod::layout()` with a built-in name extends that layout instead, as in [Your Own File Types](#your-own-file-types). [docs/layouts.md](docs/layouts.md#defining-a-layout) lists every method and option.

## Configuration

| Option | Default | Description |
| --- | --- | --- |
| `layout` | `'laravel'` | The active layout: a built-in name or one you define |
| `commands` | `true` | Register the `mod:*` commands |
| `generators` | `[]` | Replace the command behind a file type, by type |
| `layouts.ddd.bases` | `null` each | The class DTOs, view models and actions extend |
| `discovery.enabled` | `true` | Register discovered providers, commands, listeners and subscribers |
| `discovery.kinds` | `[]` | Discover more file types, or `false` to skip one, such as `'migration' => false` |
| `discovery.cache` | `'bootstrap/cache/mod-discovery.php'` | Where the discovery cache is written |
| `discovery.on_stale_cache` | `'scan'` | `'scan'` ignores an outdated cache with a warning; `'fail'` stops the app booting |
| `discovery.factories` | `true` | Find factories for models the layout places |
| `discovery.policies` | `true` | Find policies for models the layout places |

## Production

`php artisan optimize` caches discovery, and `php artisan optimize:clear` clears it:

```bash
php artisan mod:discovery-cache   # also run by optimize
php artisan mod:discovery-clear   # also run by optimize:clear
```

Like Laravel's own caches, the discovery cache doesn't pick up new classes. Run `php artisan optimize:clear` after adding a provider, command or listener while it exists.

## FAQ

### Can I Add Mod to an Existing App?

Yes. Mod doesn't move or change existing files, and `make:*` keeps working. Start on the `laravel` layout, which places files like `make:*`, and switch layouts when you're ready. Classes already in Laravel's default folders keep working as before.

### Do Folders Outside `app/` Need Autoloading?

Yes. A layout that writes outside `app/`, such as `ddd`'s `src/Domain`, needs a PSR-4 entry in your `composer.json` autoload, as shown in [The DDD Layout](#the-ddd-layout). Run `composer dump-autoload` after adding it.

### How Is This Different from nwidart/laravel-modules or InterNACHI/modular?

| | [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules) | [InterNACHI/modular](https://github.com/InterNACHI/modular) | Mod |
| --- | --- | --- | --- |
| Structure | `Modules/<Module>/` | `app-modules/<module>/` | a built-in layout or your own |
| Per module | config, plus a Composer merge plugin | a `composer.json` | a folder |
| Generators | `module:make-*` | `make:*` with `--module=` | `mod:*`, built on `make:*` |
| Discovered | providers | providers, commands, migrations, factories, policies, listeners, Blade components, translations | providers, commands, listeners, subscribers, migrations, factories, policies |

Both are mature. nwidart/laravel-modules also enables and disables modules at runtime and handles per-module assets. InterNACHI/modular also loads Blade components and translations. Mod doesn't load per-module routes, views, translations or assets yet; routes and views are planned. Choose mod to keep a structure you already have, or to use DDD, feature folders or vertical slices instead of one module format.

## Documentation

- [Layouts](docs/layouts.md): every built-in layout's folders, defining a layout, and adding a layer
- [Discovery](docs/discovery.md): what is discovered where, caching, and supplying your own files
- [Extending Mod](docs/extending.md): writing a package that adds file types, stubs and commands

## Testing

```bash
composer test
composer analyse
composer lint
```

## Changelog

See [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Contributing

See [CONTRIBUTING](https://github.com/teylabs/.github/blob/main/CONTRIBUTING.md). Questions and ideas go to [Discussions](https://github.com/teylabs/mod/discussions).

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jasper Tey](https://github.com/jaspertey)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
