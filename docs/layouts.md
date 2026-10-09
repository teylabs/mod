# Layouts

This page lists where every built-in layout puts each file, then shows how to define a layout of your own or add a layer to a built-in one. When you're done, `mod:*` commands write to every folder your application uses.

For choosing a layout, see the [README](../README.md#choosing-a-layout).

## Built-in layouts

The `laravel` layout puts every file where the matching `make:*` command does. `type-first` uses the same folders with an optional sub-folder: `mod:job Knowledge:ExtractText` writes `app/Jobs/Knowledge/ExtractText.php`, and `mod:job ExtractText` writes `app/Jobs/ExtractText.php`. Both also have `mod:config`, on Laravel versions with `make:config`.

The other layouts put each file in a folder below its group:

| Layout | Group folder |
| --- | --- |
| `modules` | `app/Modules/<Module>` |
| `features` | `app/Features/<Feature>` |
| `slices` | `app/<Feature>`, and `app/<Feature>/<Slice>` for a slice's classes |
| `ddd` | `src/Domain/<Domain>` |

| Command | `modules` | `features` | `slices` | `ddd` |
| --- | --- | --- | --- | --- |
| `mod:action` | `Actions` | | | `Actions` |
| `mod:cast` | `Casts` | `Casts` | `Casts` | `Casts` |
| `mod:channel` | `Channels` | `Broadcasting` | `Broadcasting` | `Channels` |
| `mod:class`, `mod:interface`, `mod:trait` | the group folder | the group folder | the group folder | the group folder |
| `mod:command` | `Console` | `Console/Commands` | `Console/Commands` | `Commands` |
| `mod:controller` | `Http/Controllers` | `Http/Controllers` | `Http/Controllers` | `app/Modules/<Domain>/Controllers` |
| `mod:dto` | `Data` | | | `Data` |
| `mod:enum` | `Enums` | `Enums` | `Enums` | `Enums` |
| `mod:event` | `Events` | `Events` | `Events` | `Events` |
| `mod:exception` | `Exceptions` | `Exceptions` | `Exceptions` | `Exceptions` |
| `mod:factory` | `Database/Factories` | `Database/Factories` | `Database/Factories` | `Database/Factories` |
| `mod:handler` | | | `<Slice>/Handler.php` | |
| `mod:job` | `Jobs` | `Jobs` | `Jobs` | `Jobs` |
| `mod:job-middleware` | `Jobs/Middleware` | `Jobs/Middleware` | `Jobs/Middleware` | `Jobs/Middleware` |
| `mod:listener` | `Listeners` | `Listeners` | `Listeners` | `Listeners` |
| `mod:mail` | `Mail` | `Mail` | `Mail` | `Mail` |
| `mod:message` | | | `<Slice>/Command.php` | |
| `mod:middleware` | `Http/Middleware` | `Http/Middleware` | `Http/Middleware` | `app/Modules/<Domain>/Middleware` |
| `mod:migration` | `Database/Migrations` | `Database/Migrations` | `Database/Migrations` | `Database/Migrations` |
| `mod:model` | `Models` | `Models` | `Models` | `Models` |
| `mod:notification` | `Notifications` | `Notifications` | `Notifications` | `Notifications` |
| `mod:observer` | `Observers` | `Observers` | `Observers` | `Observers` |
| `mod:policy` | `Policies` | `Policies` | `Policies` | `Policies` |
| `mod:provider` | `Providers` | `Providers` | `Providers` | `Providers` |
| `mod:query` | `Queries` | `Queries` | `<Slice>/Query.php` | |
| `mod:request` | `Http/Requests` | `Http/Requests` | `<Slice>/Http/Requests/Request.php` | `app/Modules/<Domain>/Requests` |
| `mod:resource` | `Http/Resources` | `Http/Resources` | `Http/Resources` | `Resources` |
| `mod:rule` | `Rules` | `Rules` | `Rules` | `Rules` |
| `mod:scope` | `Scopes` | `Scopes` | `Scopes` | `Scopes` |
| `mod:seeder` | `Database/Seeders` | `Database/Seeders` | `Database/Seeders` | `Database/Seeders` |
| `mod:test` | `tests/Feature/Modules/<Module>` | `tests/Feature/<Feature>` | `tests/Feature/<Feature>/<Slice>` | `tests/Feature/<Domain>` |
| `mod:validator` | | `Validation` | `<Slice>/Validator.php` | |
| `mod:value-object` | `ValueObjects` | | | `ValueObjects` |
| `mod:view-model` | `ViewModels` | | | `ViewModels` |

- A slice's classes have fixed names, so they need no name: `mod:handler --in=Knowledge/IndexDocument` writes `app/Knowledge/IndexDocument/Handler.php`. A different name is not used, and the command says so.
- `mod:test --unit` writes to `tests/Unit` instead of `tests/Feature`.
- `mod:provider` adds `ServiceProvider` to the name, except in `ddd`, which names providers as given, as laravel-ddd and `make:provider` do.
- In `modules`, `mod:dto` answers to `mod:data` as well, and `mod:value-object` to `mod:value`.
- Every hyphenated command also works without the dash: `mod:viewmodel`, `mod:valueobject`, `mod:jobmiddleware`. This applies to your own file types too, unless the name is already taken.
- In `features` and `slices`, `mod:command` without a feature writes to `app/Console/Commands`.

## The DDD layout

```php
// config/mod.php
'layout' => 'ddd',
```

```bash
php artisan mod:model Knowledge:Document --factory
# -> src/Domain/Knowledge/Models/Document.php
# -> src/Domain/Knowledge/Database/Factories/DocumentFactory.php
```

The folders match [laravel-ddd](https://github.com/teylabs/laravel-ddd), so a laravel-ddd application keeps its structure. Each class belongs to a domain, given as `--domain=Knowledge`, `--in=Knowledge` or the `Knowledge:` prefix. A domain can be nested: `Knowledge.Search` (or `Knowledge/Search`) writes to `src/Domain/Knowledge/Search/...`.

| Namespace | Folder | Holds |
| --- | --- | --- |
| `Domain\` | `src/Domain` | models, DTOs, value objects, view models, actions and the other domain classes |
| `App\Modules\` | `app/Modules` | controllers, requests and middleware |
| `Tests\` | `tests` | tests, in `tests/Feature/<Domain>` |

The DDD commands also answer to laravel-ddd's names:

| Command | Aliases |
| --- | --- |
| `mod:dto` | `mod:data`, `mod:data-transfer-object`, `mod:datatransferobject` |
| `mod:value-object` | `mod:value`, `mod:valueobject` |
| `mod:view-model` | `mod:viewmodel` |

Run `php artisan mod:autoload` to add the missing mapping and reload Composer. The resulting entry in `composer.json` is:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Domain\\": "src/Domain/"
    }
}
```

DTOs, view models, value objects and actions start from [starter stubs](#starter-stubs). In this layout, their base classes go in `src/Domain/Shared`, where laravel-ddd puts them.

## Starter stubs

`mod:dto`, `mod:view-model`, `mod:value-object` and `mod:action` start as plain Laravel-style classes. When a package for them is installed, mod uses it instead:

| Command | When installed | Otherwise |
| --- | --- | --- |
| `mod:dto` | [spatie/laravel-data](https://github.com/spatie/laravel-data): extends `Data` | extends a `DataTransferObject` base with `fromArray()` and `toArray()` |
| `mod:view-model` | [spatie/laravel-view-models](https://github.com/spatie/laravel-view-models): extends `ViewModel` | extends a `ViewModel` base |
| `mod:action` | [lorisleiva/laravel-actions](https://github.com/lorisleiva/laravel-actions): `use AsAction;` | a plain class with `handle()` |
| `mod:value-object` | | a plain class with a constructor |

The `modules` and `ddd` layouts have all four commands. In any layout, a file type with the id `dto` (or `data`), `view-model`, `value-object` (or `value`) or `action` starts from the matching starter. Add one to the `features` layout and it starts as a DTO:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

Mod::layout('features')->generates('dto', in: 'Features/{feature}/Data');
```

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Created base class App\Support\Data\DataTransferObject [app/Support/Data/DataTransferObject.php].
# ->  INFO  DTO [app/Features/Knowledge/Data/DocumentData.php] created successfully.
```

A file type with another id uses a starter through `stub:`, for example `->generates('payload', in: 'Features/{feature}/Payloads', stub: Starters::dto())` with `use Tey\Mod\Generation\Starters;`. `Starters::dto(baseIn: 'Shared/Data')` and `Starters::viewModel(baseIn: ...)` put the generated base below the file type's own root instead of `bases_path`, as `ddd` does.

### Generated base classes

Without spatie/laravel-data, the first `mod:dto` writes a `DataTransferObject` base into your app, and the first `mod:view-model` a `ViewModel` base. They go in `app/Support`: `App\Support\Data\DataTransferObject` and `App\Support\ViewModels\ViewModel`, the same classes for every module. Set `bases_path` in `config/mod.php` to put them somewhere else. The `ddd` layout keeps them in `src/Domain/Shared`:

```bash
php artisan mod:dto Knowledge:DocumentData   # with 'layout' => 'ddd'
# ->  INFO  Created base class Domain\Shared\Data\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].
# ->  INFO  DTO [src/Domain/Knowledge/Data/DocumentData.php] created successfully.
```

A base is yours from then on: mod never overwrites it, not even with `--force`. To change what it starts as, add `stubs/mod.base.data-transfer-object.stub` or `stubs/mod.base.view-model.stub` to your app.

### Writing missing bases

A module copied from another project refers to bases it doesn't contain. `mod:bases` writes every missing base your layout's file types extend:

```bash
php artisan mod:bases
# ->  INFO  Created base class App\Support\Data\DataTransferObject [app/Support/Data/DataTransferObject.php].
# ->  INFO  Created base class App\Support\ViewModels\ViewModel [app/Support/ViewModels/ViewModel.php].
```

It never overwrites a base. When every base exists, it prints "Every base class already exists." and writes nothing.

<a id="extending-your-own-base-class"></a>

### Using your own base class

To extend a class of your own instead, set it in `config/mod.php`, by file type. A configured base wins over an installed package:

```php
// config/mod.php
'bases' => [
    'dto' => App\Support\BaseData::class,
],
```

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Using the configured base App\Support\BaseData.
```

`view-model`, `value-object` and `action` take a base the same way. To change any generated class, add `stubs/mod.<type>.stub` to your app, for example `stubs/mod.dto.stub`. It can use `{{ baseImport }}` and `{{ extends }}` for the base mod chose.

## Defining a layout

A layout is one chain in a service provider. Imports belong at file scope; registrations go in `boot()`. Name it in `config/mod.php` to use it:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

Mod::layout('domains')
    ->mounts('domain', 'Domain\\', 'src/Domain', fn (Root $root) => $root
        ->generates('model', in: '{domain}/Models')
        ->generates('action', in: '{domain}/Actions'))
    ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
        ->generates('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
    ->generates('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
    ->relates('model', 'factory')
    ->excludes('App\\Support\\');
```

```php
// config/mod.php
'layout' => 'domains',
```

```bash
php artisan mod:model Knowledge:Document --factory
# -> src/Domain/Knowledge/Models/Document.php
# -> src/Domain/Knowledge/Database/Factories/DocumentFactory.php
```

Calling `Mod::layout()` with an existing name customizes that layout. Repeating a file type, root or relation changes only the arguments you pass:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('features')->generates('job', in: 'Features/{feature}/Queue');
```

`in:` is relative to the file type's root, not to the group folder, so the job above writes `app/Features/Knowledge/Queue/ExtractText.php`. A repeated file type keeps its root. A new one goes in the layout's first root, unless `in:` starts with another root's name, as in [Adding a layer](#adding-a-layer):

| Layout | First root | Other roots |
| --- | --- | --- |
| `laravel`, `type-first` | `app` | `database/factories`, `database/seeders`, `database/migrations`, `config`, `tests` |
| `modules`, `features`, `slices` | `app` | `tests` |
| `ddd` | `src/Domain` (`domain`) | `app/Modules` (`application`), `tests` |

The layout is checked the first time it is used. Every problem is reported at once, each naming the call that caused it.

### Roots

`mounts($name, $namespace, $path, $closure)` maps a namespace to a folder. File types declared inside the closure live in that root. A `null` namespace makes a root for plain files, such as config files.

### File types

`generates($id, in: ...)` declares a file type and its folder below the root. A `root:` prefix (`domain:{domain}/...`) places it in another root. Without one, it uses the enclosing `mounts()` closure's root, or else the first declared root.

| Argument | Example | Effect |
| --- | --- | --- |
| `suffix:` | `'Controller'` | appended to the class name |
| `fixed:` | `'Handler'` | a fixed class name, whatever name is given |
| `timestamped:` | `true` | a timestamped file name, as for migrations |
| `nested:` | `true` | accepts names like `Archived/Document`, as `make:model Archived/Document` does |
| `command:` | `'mod:repo'` | the command name, `mod:<id>` by default; `false` for none |
| `aliases:` | `['mod:repository']` | more command names |
| `label:` | `'DTO'` | the noun the command prints: "DTO [...] created successfully." |
| `ungrouped:` | `'Console/Commands'` | the folder used when the group is left out |
| `discover:` | `'anywhere'` | discovered in every folder below the group instead of its own folder (`'folder'`, the default), with `discoverExcept: ['Tests']` to skip some (see [Discovery](discovery.md)) |

A file type with no matching Laravel generator starts as an empty class. Put a `stubs/mod.<type>.stub` in your application to change it.

### Related files

`relates($from, $to, ...)` connects two file types. It drives options such as `--factory` and `--policy`, and how one class refers to another. An id names both ends, `<from>-<to>`, so the built-in layouts have `model-factory`, `model-seeder`, `model-policy`, `model-controller`, `model-migration`, `factory-model`, `listener-event`, `controller-store-request`, `controller-update-request`, `model-store-request` and `model-update-request`, and `slices` adds `handler-request` and `request-model`. Repeat the same pair to change that relation in a built-in layout; `as:` supplies a different relation id. This names a model's seeder `DocumentDataSeeder` instead of `DocumentSeeder`:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('modules')->relates('model', 'seeder', name: ['suffix' => 'Data']);
```

- `name:` says how the related name derives from the original: `'explicit'` (always named by the caller), or a map of `strip-suffix`, `prefix` and `suffix`, such as `['prefix' => 'Store']`. The related type's own `suffix:` or `fixed:` still applies.
- `scope: ['nested' => 'drop']` stops nested folders carrying over. By default they do: `Models/Archived/Document` relates to `Policies/Archived/DocumentPolicy`.
- `mode:` is `'generate'` (create the related file), `'reference'` (refer to it only) or `'none'`.

### Exclusions

`excludes(...)` marks namespaces or paths inside a root that no file type owns. Mod never places anything there, and discovery skips them.

### Placeholders

Placeholders in `in:` are the layout's dimensions: the ways it groups code. Their order of first appearance is the order of values in `--in`, and each one is also an option of the commands whose folder uses it.

| Placeholder | Meaning | Value |
| --- | --- | --- |
| `{feature}` | one folder | `--feature=Knowledge` or `--in=Knowledge` |
| `{feature?}` | an optional folder | omit it, or `--feature=Knowledge` |
| `{area+}` | one or more folders | `--area=Knowledge.Search` writes to `.../Knowledge/Search/...` |

### Renaming an option

An option is named after its placeholder. To call it something else, change the token in the group path:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('modules')->path('app/Modules/{area}');       // mod:model Document --area=Knowledge
Mod::layout('slices')->path('app/{feature}/{operation}'); // --feature=Knowledge --operation=IndexDocument
```

The path tokens name the options, anchors and placeholders. File type paths follow the new group path.

### When an option name is already taken

Some Laravel commands already have an option that could share a placeholder's name. If your layout writes controllers to `Http/Controllers/{model}`, a `--model` option would clash with `make:controller --model`. Mod keeps Laravel's option, leaves the placement option out and logs a warning. `--in` and the short form still work. Change the token in `path()` to get the placement option back.

## Extending a layout

Use `->extends()` first in the chain to copy a layout, then customize the copy:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
```

Choose `'layout' => 'areas'` in `config/mod.php`, then generate:

```bash
php artisan mod:autoload
php artisan mod:model Billing:Invoice
# -> src/Areas/Billing/Models/Invoice.php
```

`extends()` copies the parent at that moment; later parent changes do not flow through. A token derived from the parent's name follows the child's name (`modules` → `areas` uses `area`). An explicit parent token is inherited until the child supplies its own `path()`.

`path()` is relative to the project root; absolute paths work too. A type-first path uses a wildcard for the file type folder: `->path('app/*/{feature}')`. Custom layouts can infer their group path from file type paths. `->allowsNesting()` permits nested group values; `->allowsNesting('feature')` names the dimension when there are several.

## Adding a layer

Add a root to the built-in layout from a service provider. Its file types use the same `{domain+}` placeholder, so they take the same `--domain` option and `Knowledge:` prefix:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

Mod::layout('ddd')
    ->mounts('infrastructure', 'Infrastructure\\', 'src/Infrastructure', fn (Root $root) => $root
        ->generates('repository', in: '{domain+}/Repositories', suffix: 'Repository')
        ->generates('client', in: '{domain+}/Clients', suffix: 'Client'));
```

```bash
php artisan mod:repository Knowledge:Document
# -> src/Infrastructure/Knowledge/Repositories/DocumentRepository.php

php artisan mod:client Knowledge.Search:Index
# -> src/Infrastructure/Knowledge/Search/Clients/IndexClient.php
```

Run `php artisan mod:autoload` to add the Infrastructure mapping as well.

A file type with no Laravel generator (`repository` and `client` above) starts as an empty class. To change what it starts as, add `stubs/mod.repository.stub` to your application:

```php
<?php
// stubs/mod.repository.stub

namespace {{ namespace }};

class {{ class }}
{
    //
}
```

To put a Laravel file type in the new layer, declare it there with a `root:` prefix. This moves every job to `src/Infrastructure/<Domain>/Jobs`:

```php
use Tey\Mod\Facades\Mod;

Mod::layout('ddd')->generates('job', in: 'infrastructure:{domain+}/Jobs');
```

## Frontend Files and Routes

Configure frontend folders with `->frontend(pages:, components:, css:, views:, pageName:)`. Plain module resources and routes are excluded from class discovery. See [Frontend Files](https://mod.teylabs.com/going-further/frontend) for pages, views and generator templates, and [Module Routes](https://mod.teylabs.com/going-further/routes) for `Mod::routes()` and registrars.
