# Extending Mod

A package can build on mod instead of shipping its own generators. When you're done, your package adds kinds and commands to a layout, ships the stubs they start from, and uses another package's base class when it is installed.

## Writing a Mod Plugin

A mod plugin is an ordinary Laravel package whose service provider calls the `Mod` facade in `boot()`. Require `tey/mod` in its `composer.json`:

```bash
composer require tey/mod
```

Everything below goes in that provider. Mod reads it when Artisan starts, so the order of providers doesn't matter.

### Adding Kinds, Commands and Aliases

Extend a built-in layout with `Mod::layout()`. A new kind gets a `mod:<kind>` command; `command:` renames it and `aliases:` adds other names:

```php
// src/BillingToolsServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    Mod::layout('ddd')
        ->kind('builder', in: '{domain+}/Builders', suffix: 'Builder', aliases: ['mod:query-builder']);
}
```

```bash
php artisan mod:builder Billing:Invoice
# -> src/Domain/Billing/Builders/InvoiceBuilder.php
php artisan mod:query-builder Billing:Payment
# -> src/Domain/Billing/Builders/PaymentBuilder.php
```

- Repeating an existing kind changes only the arguments you pass. Aliases add up: `->kind('dto', aliases: ['mod:payload'])` keeps `mod:data` and the DTO's other aliases.
- A command or alias that another kind already uses stops the layout from compiling, with an error naming both kinds.
- The layout methods are listed in the [README](../README.md#defining-or-extending-a-layout).

### Registering Stubs

A kind with no Laravel generator starts as a plain class. Register a stub for it:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->for('builder', Stub::file(__DIR__.'/../stubs/builder.stub'));
```

```php
// stubs/builder.stub
<?php

namespace {{ namespace }};

use Illuminate\Database\Eloquent\Builder;

class {{ class }} extends Builder
{
    //
}
```

`Mod::stubs()->for()` works for any kind, including those with a Laravel generator. The stub that is used is the first that exists:

1. the application's `stubs/mod.<kind>.stub`;
2. the stub registered with `Mod::stubs()->for()` (the last registration wins);
3. the stub the layout declares (`kind(..., stub: ...)`);
4. the Laravel generator's stub, or mod's plain class.

| Placeholder | Filled with |
| --- | --- |
| `{{ namespace }}`, `{{ class }}` | the class's namespace and short name |
| `{{ base }}`, `{{ baseClass }}` | the base class's full and short name |
| `{{ baseImport }}` | its `use` line, or nothing when there is no base |
| `{{ extends }}` | ` extends <baseClass>`, or nothing when there is no base |

### Using Another Package When It Is Installed

A stub can name variants. The first whose package is installed (`whenInstalled`) or whose class exists (`whenClass`) supplies the base class, the stub, or both:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
    ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
    ->whenClass('App\\Support\\Data', stub: __DIR__.'/../stubs/dto.app.stub'));
```

```bash
php artisan mod:dto Billing:InvoiceData
# ->  INFO  Using spatie/laravel-data (installed).
```

`stubs/dto.stub` uses `{{ baseImport }}` and `{{ extends }}`, so one file serves every variant:

```php
// stubs/dto.stub
<?php

namespace {{ namespace }};
{{ baseImport }}
class {{ class }}{{ extends }}
{
    public function __construct(
        //
    ) {}
}
```

An explicit base wins over every variant. The application sets one in `config/mod.php` (`'layouts' => ['ddd' => ['bases' => ['dto' => ...]]]`); a plugin can read its own config key and give a default with `->base(config: 'billing.base_dto', class: 'App\\Support\\Data')`.

### Generating a Base Class

When no variant applies, a stub can write a base class into the application the first time it is used:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\Stub;

Mod::stubs()->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
    ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
    ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')));
```

```bash
php artisan mod:dto Billing:InvoiceData
# ->  INFO  Created base class Domain\Shared\Data\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].
# ->  INFO  Dto [src/Domain/Billing/Data/InvoiceData.php] created successfully.
```

- `in:` is a folder below the kind's root, so the base above lands in `src/Domain/Shared/Data`.
- The base stub fills `{{ namespace }}` and `{{ class }}`. The application can replace it by publishing `stubs/mod.base.data-transfer-object.stub` (the base's name in kebab-case).
- Once the file exists, the application owns it: mod never overwrites it, even with `--force`.
- Stubs must not use mod's own classes, so the generated code runs without mod installed.

### Swapping a Generator

Replace the command behind a kind with `Mod::generators()->use()`. Extend the adapter it replaces: `GenericClassCommand` for kinds with no Laravel generator, or the matching command such as `ModelCommand`:

```php
// src/Commands/BuilderCommand.php
<?php

namespace Billing\Tools\Commands;

use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Generation\GenerationPlan;

class BuilderCommand extends GenericClassCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        $this->components->info('Add a newEloquentBuilder() method to the model to use it.');
    }
}
```

```php
use Billing\Tools\Commands\BuilderCommand;
use Tey\Mod\Facades\Mod;

Mod::generators()->use('builder', BuilderCommand::class);
```

The protected hooks an adapter offers are listed in the [README](../README.md#building-your-own-generators).

## Example: A DDD Plugin

The provider below is the shape of [laravel-ddd](https://github.com/teylabs/laravel-ddd) on mod. It keeps laravel-ddd's own config keys for base classes, adds a kind the built-in layout doesn't have, and ships its own stubs:

```php
// src/DddServiceProvider.php
<?php

namespace Acme\Ddd;

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\Stub;

class DddServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mod::layout('ddd')
            ->kind('builder', in: '{domain+}/Builders', suffix: 'Builder');

        Mod::stubs()
            ->for('builder', Stub::file(__DIR__.'/../stubs/builder.stub'))
            ->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
                ->base(config: 'ddd.base_dto')
                ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
                ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')))
            ->for('view-model', Stub::file(__DIR__.'/../stubs/view-model.stub')
                ->base(config: 'ddd.base_view_model')
                ->whenInstalled('spatie/laravel-view-models', base: 'Spatie\\ViewModels\\ViewModel'));
    }
}
```

```bash
php artisan mod:builder Billing:Invoice
# -> src/Domain/Billing/Builders/InvoiceBuilder.php
php artisan mod:view-model Billing:ShowInvoice
# with ddd.base_view_model set to Domain\Shared\ViewModels\ViewModel:
# ->  INFO  Using the configured base Domain\Shared\ViewModels\ViewModel.
```
