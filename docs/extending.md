# Extending Mod

A package can build on mod to add file types and commands to a layout, ship generator templates and scaffold recipes, and use another package's base class when it is installed.

## Writing a mod plugin

A mod plugin is an ordinary Laravel package whose service provider calls the `Mod` facade in `boot()`. Require `tey/mod` in its `composer.json`:

```bash
composer require tey/mod
```

Everything below goes in that provider. Mod reads it when Artisan starts, so the order of providers doesn't matter.

<a id="adding-file-types-commands-and-aliases"></a>

### Adding file types and commands

Customize a built-in layout with `Mod::layout()`. A new file type gets a `mod:<type>` command; `command:` renames it, `aliases:` adds other names and `label:` sets the noun its output uses:

```php
// src/KnowledgeToolsServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    Mod::layout('ddd')
        ->generates('builder', in: '{domain+}/Builders', suffix: 'Builder', aliases: ['mod:query-builder'], label: 'Query builder');
}
```

```bash
php artisan mod:builder Knowledge:Document
# ->  INFO  Query builder [src/Domain/Knowledge/Builders/DocumentBuilder.php] created successfully.
php artisan mod:query-builder Knowledge:Chunk
# -> src/Domain/Knowledge/Builders/ChunkBuilder.php
```

- Repeating an existing file type changes only the arguments you pass. Aliases add up: `->generates('dto', aliases: ['mod:payload'])` keeps `mod:data` and the DTO's other aliases.
- Without `label:`, the output names the type's id in title case (`Builder`). File types with a Laravel generator keep Laravel's wording.
- A hyphenated command or alias also gets a dash-free alias, so `mod:query-builder` works as `mod:querybuilder`. When that name is already a command or alias, the existing one keeps it.
- A command or alias that another file type already uses stops the layout from compiling, with an error naming both.
- `Mod::hasLayout('ddd')` checks that a layout exists before customizing it, and `Mod::layouts()` lists every layout name.
- The layout methods are listed in [Defining a layout](layouts.md#defining-a-layout).

### Registering stubs

A file type with no Laravel generator starts as an empty class. Register a stub for it:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;

Mod::stubs()->for('builder', Stub::file(__DIR__.'/../stubs/builder.stub'));
```

```php
<?php
// stubs/builder.stub

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
3. the stub the layout declares (`generates(..., stub: ...)`);
4. the starter for the file type's id: `dto`, `view-model`, `value-object` or `action` (see [Starter stubs](layouts.md#starter-stubs));
5. the Laravel generator's stub, or mod's empty class.

A file type with another id uses a starter through `stub:`, such as `->generates('payload', in: '{domain+}/Payloads', stub: Starters::dto())` with `use Tey\Mod\Generation\Starters;`.

| Placeholder | Filled with |
| --- | --- |
| `{{ namespace }}`, `{{ class }}` | the class's namespace and short name |
| `{{ base }}`, `{{ baseClass }}` | the base class's full and short name |
| `{{ baseImport }}` | its `use` line, or nothing when there is no base |
| `{{ extends }}` | ` extends <baseClass>`, or nothing when there is no base |

### Using another package when it is installed

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
<?php
// stubs/dto.stub

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

### Generating a base class

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
- To place the base below the file type's own root instead, add `->inFileTypeRoot()`. In the `ddd` layout, `GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: ...)->inFileTypeRoot()` writes `src/Domain/Shared/Data/DataTransferObject.php`.
- The base stub fills `{{ namespace }}` and `{{ class }}`. The application can replace it by publishing `stubs/mod.base.data-transfer-object.stub` (the base's name in kebab-case).
- Once the file exists, the application owns it: mod never overwrites it, even with `--force`. `mod:bases` writes it when it is missing.
- Stubs must not use mod's own classes, so the generated code runs without mod installed.

### Swapping a generator

Replace the command behind a file type with `Mod::generators()->use()`. Extend the adapter it replaces: `GenericClassCommand` for file types with no Laravel generator, or the matching command such as `ModelCommand`:

```php
<?php
// src/Commands/BuilderCommand.php

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

The protected hooks an adapter offers are listed in [Building your own generators](#building-your-own-generators).

## Example: a DDD plugin

The provider below is the shape of [laravel-ddd](https://github.com/teylabs/laravel-ddd) on mod. It keeps laravel-ddd's own config keys for base classes, adds a file type the built-in layout doesn't have, and ships its own stubs:

```php
<?php
// src/DddServiceProvider.php

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
            ->generates('builder', in: '{domain+}/Builders', suffix: 'Builder');

        Mod::stubs()
            ->for('builder', Stub::file(__DIR__.'/../stubs/builder.stub'))
            ->for('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
                ->base(config: 'ddd.base_dto')
                ->whenInstalled('spatie/laravel-data', base: 'Spatie\\LaravelData\\Data')
                ->generatesBase(GeneratedBase::named('DataTransferObject', in: 'Shared/Data', stub: __DIR__.'/../stubs/bases/data-transfer-object.stub')->inFileTypeRoot()))
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

## Building your own generators

Every `mod:*` command is a subclass of the matching Laravel command, such as `Tey\Mod\Commands\ModelCommand`, `ControllerCommand`, `RequestCommand`, `FactoryCommand` and `MigrationCommand`. A package with its own command catalog can extend these instead of the native commands and override a few protected hooks:

| Hook | Use |
| --- | --- |
| `placementInput()` | the placement in `--in` syntax, for example from your own option or prompt |
| `placementContext()` | the placement context the command resolves against |
| `placementOptions()` | which placement options the command adds: option name => the dimension it sets, with `null` for `--in`. Return `[]` to add none; related commands then receive the `Group:Name` form |
| `resolveLayout()`, `kindId()` | the layout and file type, for commands not registered through the layout |
| `layout()`, `kind()` | read the compiled layout and the command's file type from inside a hook |
| `stubDefinition()` | the `Stub` the class is generated from: by default the one registered with `Mod::stubs()`, else the layout's |
| `collisionPolicy()` | `CollisionPolicy::Refuse` (mod checks every file before writing) or `CollisionPolicy::Native` (the native generator's own check and `--force` decide) |
| `plansEagerly()`, `resolvePlan()` | plan from inside your own `handle()` |
| `beforeGeneration(GenerationPlan $plan)`, `afterGeneration(GenerationPlan $plan, int $exitCode)` | run code around generation |
| `nativePathAllowed()` | on `MigrationCommand`: let `--path` and `--realpath` through |
| `reportRefusal(ModException $e)`, `reportReference(ResolvedArtifact $target)` | the only places the commands print on their own |

These hooks are the API a subclass can rely on. The commands' other protected methods are internal and may change in any release.

`Mod::current()` returns the active layout, compiled (`Tey\Mod\Layout\CompiledLayout`): `dimensionNames()` lists its dimensions in order, and `placementOptions()` maps each one to its option name.

Every exception mod throws extends `Tey\Mod\Exceptions\ModException`. `UnknownFileType` names a file type the layout doesn't have, `InvalidName` a class name the file type can't take, and `InvalidLayout` a layout that isn't defined, a `mod.layout` value that isn't a layout name, or a layout definition with errors.

## Turning commands off

A package or application with its own Artisan commands can keep mod's placement and discovery without the `mod:*` commands:

```php
// config/mod.php
'commands' => false,
```

Or turn them off for one layout with `Mod::layout('mine')->withoutCommands()`. Its file types keep their `command:` names for a host to dispatch by, and several file types may then share one. The discovery cache commands are registered only while the `mod:*` commands and discovery are both on.

## Shipping generator templates

Register a folder from the package's provider. Use the neutral `@group` anchor so the generated folder follows the application's layout:

```php
// src/ToolsServiceProvider.php: imports at file scope, registration in boot().
use Tey\Mod\Facades\Mod;

Mod::stubs()->folder(__DIR__.'/../stubs/mod');
```

For example, `stubs/mod/@group/Tools/tool.stub` gives the application a `mod:tool` command:

```php
<?php
// stubs/mod/@group/Tools/tool.stub

namespace {{ namespace }};

class {{ class }}
{
    //
}
```

With the `modules` layout:

```bash
php artisan mod:tool Knowledge:SearchDocuments --no-interaction
# -> app/Modules/Knowledge/Tools/SearchDocuments.php
```

The application's template takes precedence. Two packages claiming the same command disable only that command with a warning naming both; the other commands keep working.

## Shipping scaffolds

A package provider registers recipes with the same API as an application. If mod is optional, list `tey/mod` in Composer's `suggest` and guard registration:

```php
// src/ToolsServiceProvider.php: imports at file scope, registration in boot().
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;

if (class_exists(Mod::class)) {
    Mod::scaffold('document', fn (Scaffold $s) => $s
        ->makes('model', as: 'model')
        ->makes('controller', name: '{name}Controller', options: ['--resource']));
}
```

With the `modules` layout:

```bash
php artisan mod:document Knowledge:Document --no-interaction
# -> app/Modules/Knowledge/Models/Document.php
# -> app/Modules/Knowledge/Controllers/DocumentController.php
```

The application can `include('document')` in another recipe or replace it. Application registration wins; two packages using one name disable that scaffold with a warning naming both. Placement follows the application's layout. `mod:list --json` reports the effective source, including part overrides.
