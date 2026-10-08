# Extending Mod

A package can build on mod instead of shipping its own generators. When you're done, your package adds file types and commands to a layout, ships the stubs they start from, and uses another package's base class when it is installed.

## Writing a Mod Plugin

A mod plugin is an ordinary Laravel package whose service provider calls the `Mod` facade in `boot()`. Require `tey/mod` in its `composer.json`:

```bash
composer require tey/mod
```

Everything below goes in that provider. Mod reads it when Artisan starts, so the order of providers doesn't matter.

### Adding File Types, Commands and Aliases

Extend a built-in layout with `Mod::layout()`. A new file type gets a `mod:<type>` command; `command:` renames it, `aliases:` adds other names and `label:` sets the noun its output uses:

```php
// src/KnowledgeToolsServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    Mod::layout('ddd')
        ->kind('builder', in: '{domain+}/Builders', suffix: 'Builder', aliases: ['mod:query-builder'], label: 'Query builder');
}
```

```bash
php artisan mod:builder Knowledge:Document
# ->  INFO  Query builder [src/Domain/Knowledge/Builders/DocumentBuilder.php] created successfully.
php artisan mod:query-builder Knowledge:Chunk
# -> src/Domain/Knowledge/Builders/ChunkBuilder.php
```

- Repeating an existing file type changes only the arguments you pass. Aliases add up: `->kind('dto', aliases: ['mod:payload'])` keeps `mod:data` and the DTO's other aliases.
- Without `label:`, the output names the type's id in title case (`Builder`). File types with a Laravel generator keep Laravel's wording.
- A command or alias that another file type already uses stops the layout from compiling, with an error naming both.
- The layout methods are listed in [Defining a Layout](layouts.md#defining-a-layout).

### Registering Stubs

A file type with no Laravel generator starts as an empty class. Register a stub for it:

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

`Mod::stubs()->for()` works for any file type, including those with a Laravel generator. The stub that is used is the first that exists:

1. the application's `stubs/mod.<type>.stub`;
2. the stub registered with `Mod::stubs()->for()` (the last registration wins);
3. the stub the layout declares (`kind(..., stub: ...)`);
4. the starter for the file type's id: `dto`, `view-model`, `value-object` or `action` (see [Starter Stubs](layouts.md#starter-stubs));
5. the Laravel generator's stub, or mod's empty class.

A file type with another id uses a starter through `stub:`, such as `->kind('payload', in: '{domain+}/Payloads', stub: Starters::dto())` with `use Tey\Mod\Layout\BuiltIn\Starters;`.

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
    ->whenClass('App\\Support\\BaseData', stub: __DIR__.'/../stubs/dto.app.stub'));
```

```bash
php artisan mod:dto Knowledge:DocumentData
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

An explicit base wins over every variant. The application sets one in `config/mod.php` (`'bases' => ['dto' => ...]`); a plugin can read its own config key and give a default with `->base(config: 'knowledge.base_dto', class: 'App\\Support\\BaseData')`.

### Generating a Base Class

When no variant applies, a stub can write a base class into the application the first time it is used. The base goes in the application's bases folder, `app/Support` by default:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\Stub;

Mod::stubs()->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
    ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
    ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')));
```

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Created base class App\Support\Data\DataTransferObject [app/Support/Data/DataTransferObject.php].
# ->  INFO  DTO [app/Modules/Knowledge/Data/DocumentData.php] created successfully.
```

- `in:` is a folder below the bases folder, which the application sets with `bases_path` in `config/mod.php`.
- To place the base below the file type's own root instead, add `->inKindRoot()`. In the `ddd` layout, `GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: ...)->inKindRoot()` writes `src/Domain/Shared/Data/DataTransferObject.php`.
- The base stub fills `{{ namespace }}` and `{{ class }}`. The application can replace it by publishing `stubs/mod.base.data-transfer-object.stub` (the base's name in kebab-case).
- Once the file exists, the application owns it: mod never overwrites it, even with `--force`. `mod:bases` writes it when it is missing.
- Stubs must not use mod's own classes, so the generated code runs without mod installed.

### Swapping a Generator

Replace the command behind a file type with `Mod::generators()->use()`. Extend the adapter it replaces: `GenericClassCommand` for file types with no Laravel generator, or the matching command such as `ModelCommand`:

```php
// src/Commands/BuilderCommand.php
<?php

namespace Knowledge\Tools\Commands;

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
use Knowledge\Tools\Commands\BuilderCommand;
use Tey\Mod\Facades\Mod;

Mod::generators()->use('builder', BuilderCommand::class);
```

The protected hooks an adapter offers are listed in [Building Your Own Generators](#building-your-own-generators).

## Example: A DDD Plugin

The provider below is the shape of [laravel-ddd](https://github.com/teylabs/laravel-ddd) on mod. It keeps laravel-ddd's own config keys for base classes, adds a file type the built-in layout doesn't have, and ships its own stubs:

```php
// src/DddServiceProvider.php
<?php

namespace Vendor\Ddd;

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
                ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')->inKindRoot()))
            ->for('view-model', Stub::file(__DIR__.'/../stubs/view-model.stub')
                ->base(config: 'ddd.base_view_model')
                ->whenInstalled('spatie/laravel-view-models', base: 'Spatie\\ViewModels\\ViewModel'));
    }
}
```

```bash
php artisan mod:builder Knowledge:Document
# -> src/Domain/Knowledge/Builders/DocumentBuilder.php
php artisan mod:view-model Knowledge:ShowDocument
# with ddd.base_view_model set to Domain\Shared\ViewModels\ViewModel:
# ->  INFO  Using the configured base Domain\Shared\ViewModels\ViewModel.
```

## Building Your Own Generators

Every `mod:*` command is a subclass of the matching Laravel command, such as `Tey\Mod\Commands\ModelCommand`, `ControllerCommand`, `RequestCommand`, `FactoryCommand` and `MigrationCommand`. A package with its own command catalog can extend these instead of the native commands and override a few protected hooks:

| Hook | Use |
| --- | --- |
| `placementInput()` | the placement in `--in` syntax, for example from your own option or prompt |
| `placementContext()` | the placement context the command resolves against |
| `placementOptions()` | which placement options the command adds: option name => the dimension it sets, with `null` for `--in`. Return `[]` to add none; related commands then receive the `Group:Name` form |
| `resolvePreset()`, `kindId()` | the layout and file type, for commands not registered through the layout |
| `stubDefinition()` | the `Stub` the class is generated from: by default the one registered with `Mod::stubs()`, else the layout's |
| `collisionPolicy()` | `CollisionPolicy::Refuse` (mod checks every file before writing) or `CollisionPolicy::Native` (the native generator's own check and `--force` decide) |
| `plansEagerly()`, `resolvePlan()` | plan from inside your own `handle()` |
| `beforeGeneration(GenerationPlan $plan)`, `afterGeneration(GenerationPlan $plan, int $exitCode)` | run code around generation |
| `nativePathAllowed()` | on `MigrationCommand`: let `--path` and `--realpath` through |
| `reportRefusal(ModException $e)`, `reportReference(ResolvedArtifact $target)` | the only places the commands print on their own |

`Preset::dimensions()` lists the layout's dimensions in order, and `Preset::placementOptions()` maps each one to its option name. Every exception mod throws extends `Tey\Mod\Exceptions\ModException`.

## Turning Commands Off

A package or application with its own Artisan commands can keep mod's placement and discovery without the `mod:*` commands:

```php
// config/mod.php
'commands' => false,
```

Or turn them off for one layout with `Mod::layout('mine')->withoutCommands()`. Its file types keep their `command:` names for a host to dispatch by, and several file types may then share one. The discovery cache commands are registered only while the `mod:*` commands and discovery are both on.
