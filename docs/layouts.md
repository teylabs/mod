# Layouts

This page is the reference for the built-in `ddd` layout, and shows how to add a layer of your own to it. When you're done, `mod:*` commands write to every folder your application uses, including ones the built-in layout doesn't know about.

For choosing a layout and the other built-ins, see the [README](../README.md#choosing-a-layout).

## The ddd Layout

```php
// config/mod.php
'layout' => 'ddd',
```

```bash
php artisan mod:model Billing:Invoice --factory
# -> src/Domain/Billing/Models/Invoice.php
# -> src/Domain/Billing/Database/Factories/InvoiceFactory.php
```

The folders match [laravel-ddd](https://github.com/teylabs/laravel-ddd), so a laravel-ddd application keeps its structure. Each class belongs to a domain, given as `--domain=Billing`, `--in=Billing` or the `Billing:` prefix. A domain can be nested: `Reporting.Internal` (or `Reporting/Internal`) writes to `src/Domain/Reporting/Internal/...`.

| Root | Namespace | Folder | Kinds |
| --- | --- | --- | --- |
| `domain` | `Domain\` | `src/Domain` | models, DTOs, value objects, view models, actions, and the other domain classes |
| `application` | `App\Modules\` | `app/Modules` | controllers, requests, middleware |
| `tests` | `Tests\` | `tests` | tests, in `tests/Feature/<Domain>` |

| Command | Folder | Aliases |
| --- | --- | --- |
| `mod:model` | `src/Domain/<Domain>/Models` | |
| `mod:dto` | `src/Domain/<Domain>/Data` | `mod:data`, `mod:data-transfer-object`, `mod:datatransferobject` |
| `mod:value` | `src/Domain/<Domain>/ValueObjects` | `mod:value-object`, `mod:valueobject` |
| `mod:view-model` | `src/Domain/<Domain>/ViewModels` | `mod:viewmodel` |
| `mod:action` | `src/Domain/<Domain>/Actions` | |
| `mod:factory`, `mod:migration`, `mod:seeder` | `src/Domain/<Domain>/Database/Factories`, `.../Migrations`, `.../Seeders` | |
| `mod:controller` | `app/Modules/<Domain>/Controllers` | |
| `mod:request` | `app/Modules/<Domain>/Requests` | |
| `mod:middleware` | `app/Modules/<Domain>/Middleware` | |
| `mod:test` | `tests/Feature/<Domain>` | |

Every other kind (`mod:event`, `mod:job`, `mod:policy`, `mod:enum` and so on) writes to `src/Domain/<Domain>/<Type>`, for example `src/Domain/Billing/Events`. `php artisan list mod` shows them all.

Add the namespaces to your `composer.json` autoload, then run `composer dump-autoload`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Domain\\": "src/Domain/"
    }
}
```

What a DTO, view model or action starts as depends on the packages you have installed. See [Stub variants](../README.md#stub-variants).

## Adding a Layer

Add a root to the built-in layout from a service provider. Its kinds use the same `{domain+}` placeholder, so they take the same `--domain` option and `Billing:` prefix:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

public function boot(): void
{
    Mod::layout('ddd')
        ->root('infrastructure', 'Infrastructure\\', 'src/Infrastructure', fn (Root $root) => $root
            ->kind('repository', in: '{domain+}/Repositories', suffix: 'Repository')
            ->kind('client', in: '{domain+}/Clients', suffix: 'Client'));
}
```

```bash
php artisan mod:repository Billing:Invoice
# -> src/Infrastructure/Billing/Repositories/InvoiceRepository.php

php artisan mod:client Reporting.Internal:Ledger
# -> src/Infrastructure/Reporting/Internal/Clients/LedgerClient.php
```

Add `"Infrastructure\\": "src/Infrastructure/"` to your `composer.json` autoload as well.

A kind with no Laravel generator (`repository` and `client` above) gets a plain class. To change what it starts as, publish `stubs/mod.repository.stub` in your application:

```php
// stubs/mod.repository.stub
<?php

namespace {{ namespace }};

class {{ class }}
{
    //
}
```

To put a Laravel kind in the new layer, declare it there with a `root:` prefix. This moves every job to `src/Infrastructure/<Domain>/Jobs`:

```php
Mod::layout('ddd')->kind('job', in: 'infrastructure:{domain+}/Jobs');
```

The other layout methods (`suffix:`, `nested:`, relations, exclusions) are in the [README](../README.md#defining-or-extending-a-layout).
