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

Mod::stubs()->forFileType('builder', Stub::file(__DIR__.'/../stubs/builder.stub'));
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

`Mod::stubs()->forFileType()` works for any file type, including those with a Laravel generator. The stub that is used is the first that exists:

1. the application's `stubs/mod.<type>.stub`;
2. the stub registered with `Mod::stubs()->forFileType()` (the last registration wins);
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

Mod::stubs()->forFileType('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
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

Mod::stubs()->forFileType('dto', Stub::file(__DIR__.'/../stubs/dto.stub')
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

Replace the command behind a file type with `Mod::generators()->useFileType()`. Extend the adapter it replaces: `GenericClassCommand` for file types with no Laravel generator, or the matching command such as `ModelCommand`:

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

Mod::generators()->useFileType('builder', BuilderCommand::class);
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
| `resolveLayout()`, `fileTypeId()` | the layout and file type, for commands not registered through the layout |
| `layout()`, `fileType()` | read the compiled layout and the command's file type from inside a hook |
| `stubDefinition()` | the `Stub` the class is generated from: by default the one registered with `Mod::stubs()`, else the layout's |
| `collisionPolicy()` | `CollisionPolicy::Refuse` (mod checks every file before writing) or `CollisionPolicy::Native` (the native generator's own check and `--force` decide) |
| `plansEagerly()`, `resolvePlan()` | plan from inside your own `handle()` |
| `beforeGeneration(GenerationPlan $plan)`, `afterGeneration(GenerationPlan $plan, int $exitCode)` | run code around generation |
| `nativePathAllowed()` | on `MigrationCommand`: let `--path` and `--realpath` through |
| `reportRefusal(ModException $e)`, `reportReference(ResolvedArtifact $target)` | the only places the commands print on their own |

These hooks and the additional hooks in [Building on mod](#building-on-mod) are the API a subclass can rely on. All unlisted protected methods are internal.

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

## Building on mod

Packages can own their layout, discovery lifecycle and generator commands while using Mod's placement engine. Use only members marked `@api`. A public PHP method or property marked `@internal` is not a compatibility promise, even when its class is public. The published signatures, parameter names and nested value types are pinned in `tests/Fixtures/published-api.json`.

### Isolated layouts and public values

`Layout::fresh(string $name): Layout` starts with shipped defaults for a built-in name or an empty definition otherwise. It does not read or mutate app customisations. `extends()` resolves built-ins in its private registry; use `Mod::layout()` for application definitions. Call `compiled(): CompiledLayout` to compile without sealing the mutable definition. Repeated compilation produces independent values.

```php
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;

$definition = Layout::fresh('ddd');
$layout = $definition->compiled();
$invoice = $layout->place('model', 'Invoice', PlacementContext::of(['domain' => 'Billing/Internal']));
$owned = $layout->locate('Domain\\Billing\\Internal\\Models\\Invoice');
```

`CompiledLayout::place(string $fileType, string $name, PlacementContext $context, array $attributes = []): ResolvedArtifact` performs placement without writing or checking collisions. Attributes are `array<string,string|int|float|bool|null>`. `locate(string $fqcn): ?ResolvedArtifact` returns null for an unowned or ambiguous class; ownership does not prove existence.

Inspect `ResolvedArtifact::$fileType`, `$context`, `$name`, `$nested`, `fqcn(): ?string`, `namespace(): ?string`, `path(): string` and `nestedName(): string`. `namespace()` returns null for files and an empty string for global-namespace classes. For schema callbacks, `ResolvedArtifact::phpClass(string $fileType, string $namespace, string $basename, string $path, ?PlacementContext $context = null): self` preserves the chosen identity verbatim and normalises the path; it does not reapply naming policy or infer child commands.

`Artifact\CompiledFileType` is the immutable compiled metadata; `Layout\FileType` remains the mutable builder. The compiled value exposes `id`, `command`, `aliases`, `label`, `extension`, `case`, `isClass(): bool` and `isTimestamped(): bool`. Use `CompiledLayout::fileTypes(): array<string,CompiledFileType>`, `fileType(string $fileType): CompiledFileType` and `hasFileType(string $fileType): bool`. The old `ArtifactKind`, `kind()`, `kinds()`, `hasKind()` and artifact `$kind` remain internal.

Enumerate `array_keys($layout->fileTypes())` when disabling discovery. Resolve `artifact->path()` against the host base with its path utilities, retaining absolute paths and Windows drive paths. Do not inspect placement rules, identity objects, naming policies or `ExistingArtifacts`. A host with only a `domain` dimension formats its child option from `context->get('domain')`, replacing `/` with `.`, and retains its existing fallback when absent.

### Host discovery and cache policy

```php
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;

$discovery = new Discovery(layout: $layout, options: DiscoveryOptions::fromConfig([
    'cache' => 'bootstrap/cache/host-discovery.php',
    'file_types' => ['provider' => 'provider'],
]), basePath: $app->basePath());

try {
    $inventory = $discovery->readCache();
} catch (InvalidDiscoveryCache $exception) {
    $inventory = $discovery->scan(); // The host chooses its own fallback policy.
}
$providers = $inventory->ofType(DiscoveryType::Provider);
```

The constructor is `Discovery(CompiledLayout $layout, DiscoveryOptions $options, string $basePath)`. `scan(): Inventory` is a cold scan. `readCache(): Inventory` validates the existing cache and throws `InvalidDiscoveryCache` for missing, foreign, stale or malformed data. It never scans or writes, and leaves memoised application discovery state alone. `cacheInventory(): Inventory` explicitly cold-scans and writes the current cache format with no scaffold payload. Use a host-owned cache path; writing a shared app cache would replace its scaffold payload. Rebuild on deployment when a custom candidate callback changes: its fingerprint records presence, not closure contents.

`Inventory::ofType(DiscoveryType $type): array` returns `list<DiscoveredArtifact>` in inventory order; `directories(string $fileType): array` returns sorted directory paths. Entries expose `fileType`, `type`, `class`, `path`, `context: array<string,string>`, `events: list<array{event:string,method:string}>` and `target: ?string`. Preserve existing path representation; directory entries have an empty class. Rejections, payload serialisation, cache objects, fingerprint helpers and listener registration remain internal.

`DiscoveryType` is the string-backed enum `Provider`, `Command`, `Listener`, `Subscriber`, `Directory`, `Factory`, `Policy`. Use its standard `from()`, `tryFrom()` and `cases()`. `DiscoveryDefinition::forFileType(string $fileType, DiscoveryType $type, bool $enabled = true): self` constructs public candidate metadata; inspect `$fileType`, `$type` and `$enabled`. Existing definition factories with old parameter names are internal. `DiscoveryOptions::withCandidates()` accepts `Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>`; candidates do not override ownership or eligibility. Use `file_types` in configuration.

### Generator support without app feature boot

A host Laravel provider calls `ModServiceProvider::registerGenerationServices($app)` in `register()`. It supplies adapter defaults with `bindIf`, preserves host bindings and is safe to call repeatedly. It registers no Mod commands, active layout, routes, views or discovered providers/listeners. Laravel's application/files/config services are prerequisites; a bare container is insufficient for the application-dependent factories.

The host supplies its own compiled layout through `resolveLayout()` or `layout()`, and its file type id through `fileTypeId()`. Normal stubs, package variants, generated bases and model migration companions work without registering the full provider. Module-owned generator template rebinding requires the full provider and app layouts. Do not depend on the internal registry/template selection helpers for isolated layouts.

Register a package stub using `Mod::stubs()->forFileType(string $fileType, Stub $stub)` and a generator replacement using `Mod::generators()->useFileType(string $fileType, string $command)`. Old registration methods with internal vocabulary remain callable for compatibility but are not published API.

### Supported planning and dispatch hooks

These are protected hooks on the public command subclasses. Concern traits are internal implementation locations, not host mixins.

| Hook | Supported purpose |
| --- | --- |
| `plan(): GenerationPlan` | Class adapters: build a candidate with a custom primary. |
| `currentPlan(): ?GenerationPlan` | Inspect an already-resolved invocation without causing planning. |
| `plannedRelations(ResolvedArtifact $primary): array` | Select native option relations or supply host-placed request targets; returns `list<RelationResolution>`. |
| `plannedRelation(string $relationId): ?RelationResolution` | Read the running plan by id; hosts may supply a prefixed-id fallback. |
| `relatedFileType(string $role): string` | Map a native model/factory/request/migration role to a canonical file type id; defaults to the input. |
| `followRelation(RelationResolution $resolution, array $arguments = []): void` | Generate a related target or report a reference; arguments are `array<string,mixed>`. |
| `argumentsFor(ResolvedArtifact $target): array` | Format child name and placement options; returns `array<string,mixed>`. |
| `generateOwnedClass(string $fqcn, string $fileType): void` | Override missing model/parent dispatch; the file type id is already canonical. |

`fileType(): CompiledFileType` and `fileTypeId(): string` replace the old internal metadata hooks. Existing documented layout, placement, stub, collision, timing and reporting hooks stay supported. `plannedRelationsTo()`, `relationsTo()`, `placeSibling()`, `inOption()` and collision/preview/scaffold helpers remain internal. Native adapters map roles once at their call boundaries, including migration preflight. Remove translator overrides of those internal helpers. Hosts inspect `currentPlan()?->relations ?? []` and filter by public `relation->toFileType` instead.

```php
protected function relatedFileType(string $role): string
{
    return 'domain-'.$role;
}

protected function plan(): GenerationPlan
{
    $primary = $this->blueprint->artifact();
    return new GenerationPlan($primary, $this->plannedRelations($primary));
}
```

`plan()` and `plannedRelations()` can run more than once while group answers settle; they must not write files. For input preparation in `handle()`, return false from `plansEagerly()` and call `resolvePlan()` after preparation. Resolution remains idempotent per invocation and performs Mod's collision checks. `currentPlan()` is null before resolution and after invocation cleanup. Do not substitute it with a call that would resolve input or prompt too early.

`GenerationPlan` exposes `primary` and `relations: list<RelationResolution>` and accepts those public values in its constructor. Relations expose `id`, `fromFileType`, `toFileType`, `mode`; use `CompiledLayout::relations()`, `relation(string $relationId)` or `relationsFrom(string $fileType)`. To change an inherited mode, call `relates($relation->fromFileType, $relation->toFileType, mode: 'none', as: $relation->id)`. Scope/name engines remain internal.

For controller store/update forwarding, place targets through the host's request file type, then return `RelationResolution::resolved($relation, $primary, $target)` from `plannedRelations()`. A resolution exposes `relation`, `source`, `target`, `isResolved()` and `mode()`. Override `plannedRelation()` for custom relation ids and `followRelation()`/`argumentsFor()` for host dispatch. Retain generation/reference semantics and non-zero child failure propagation. Calling the parent preserves Mod's related-generation scope; bypassing it makes the host responsible for that scope and recursion policy.

### Migration creators

`MigrationCommand::__construct(Illuminate\Database\Migrations\MigrationCreator $creator, Illuminate\Support\Composer $composer)` accepts the framework creator directly. Mod adapts an exact framework creator internally, preserving filesystem and custom stub path, and pins planned timestamps. Existing internal Mod creators still work. Native `--path`/`--realpath` require the supported `nativePathAllowed(): bool` opt-in.

An application creator subclass keeps its actual native dispatch and bypasses Mod planning independently of `--path`. It has no Mod plan and does not run plan-specific callbacks. A host can retain its domain directory override using only the public framework creator classification; do not reflect into or construct a Mod creator. Arbitrary creator output cannot be promised to match a Mod timestamp/path plan.
