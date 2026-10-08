# Layouts

This page lists where every built-in layout puts each file, then shows how to define a layout of your own or add a layer to a built-in one. When you're done, `mod:*` commands write to every folder your application uses.

For choosing a layout, see the [README](../README.md#choosing-a-layout).

## Built-In Layouts

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
| `mod:controller` | `Controllers` | `Http/Controllers` | `Http/Controllers` | `app/Modules/<Domain>/Controllers` |
| `mod:data` | `Data` | | | |
| `mod:dto` | | | | `Data` |
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
| `mod:middleware` | `Middleware` | `Http/Middleware` | `Http/Middleware` | `app/Modules/<Domain>/Middleware` |
| `mod:migration` | `Database/Migrations` | `Database/Migrations` | `Database/Migrations` | `Database/Migrations` |
| `mod:model` | `Models` | `Models` | `Models` | `Models` |
| `mod:notification` | `Notifications` | `Notifications` | `Notifications` | `Notifications` |
| `mod:observer` | `Observers` | `Observers` | `Observers` | `Observers` |
| `mod:policy` | `Policies` | `Policies` | `Policies` | `Policies` |
| `mod:provider` | `Providers` | `Providers` | `Providers` | `Providers` |
| `mod:query` | `Queries` | `Queries` | `<Slice>/Query.php` | |
| `mod:request` | `Requests` | `Http/Requests` | `<Slice>/Request.php` | `app/Modules/<Domain>/Requests` |
| `mod:resource` | `Resources` | `Http/Resources` | `Http/Resources` | `Resources` |
| `mod:rule` | `Rules` | `Rules` | `Rules` | `Rules` |
| `mod:scope` | `Scopes` | `Scopes` | `Scopes` | `Scopes` |
| `mod:seeder` | `Database/Seeders` | `Database/Seeders` | `Database/Seeders` | `Database/Seeders` |
| `mod:test` | `tests/Feature/Modules/<Module>` | `tests/Feature/<Feature>` | `tests/Feature/<Feature>/<Slice>` | `tests/Feature/<Domain>` |
| `mod:validator` | | `Validation` | `<Slice>/Validator.php` | |
| `mod:value` | | | | `ValueObjects` |
| `mod:view-model` | | | | `ViewModels` |

- A slice's classes have fixed names, so `mod:handler Handler --in=Knowledge/IndexDocument` writes `app/Knowledge/IndexDocument/Handler.php`.
- `mod:test --unit` writes to `tests/Unit` instead of `tests/Feature`.
- In `features` and `slices`, `mod:command` without a feature writes to `app/Console/Commands`.

## The ddd Layout

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
| `mod:value` | `mod:value-object`, `mod:valueobject` |
| `mod:view-model` | `mod:viewmodel` |

Add the namespace to your `composer.json` autoload, then run `composer dump-autoload`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Domain\\": "src/Domain/"
    }
}
```

What a DTO, view model or action starts as depends on the packages you have installed. See [Starter Stubs and Stub Variants](../README.md#starter-stubs-and-stub-variants).

## Defining a Layout

A layout is one chain in a service provider. Name it in `config/mod.php` to use it:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

public function boot(): void
{
    Mod::layout('domains')
        ->root('domain', 'Domain\\', 'src/Domain', fn (Root $root) => $root
            ->kind('model', in: '{domain}/Models')
            ->kind('action', in: '{domain}/Actions'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
        ->kind('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
        ->relation('factory', from: 'model', to: 'factory')
        ->exclude('App\\Support\\');
}
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

Calling `Mod::layout()` with an existing name extends that layout. Repeating a file type, root or relation changes only the arguments you pass:

```php
Mod::layout('features')->kind('job', in: 'Features/{feature}/Queue');
```

The layout is checked the first time it is used. Every problem is reported at once, each naming the call that caused it.

### Roots

`root($name, $namespace, $path, $closure)` maps a namespace to a folder. File types declared inside the closure live in that root. A `null` namespace makes a root for plain files, such as config files.

### File Types

`kind($id, in: ...)` declares a file type and its folder below the root. A `root:` prefix (`domain:{domain}/...`) places it in another root. Without one, it uses the enclosing `root()` closure's root, or else the first declared root.

| Argument | Example | Effect |
| --- | --- | --- |
| `suffix:` | `'Controller'` | appended to the class name |
| `fixed:` | `'Handler'` | a fixed class name, whatever name is given |
| `timestamped:` | `true` | a timestamped file name, as for migrations |
| `nested:` | `true` | accepts names like `Archived/Document`, as `make:model Archived/Document` does |
| `command:` | `'mod:repo'` | the command name, `mod:<id>` by default; `false` for none |
| `aliases:` | `['mod:repository']` | more command names |
| `label:` | `'DTO'` | the noun the command prints: "DTO [...] created successfully." |
| `fallback:` | `'Console/Commands'` | the folder used when the group is left out |
| `discoverAnywhere:` | `true` | discovered in every folder below the group, with `except: ['Tests']` to skip some (see [Discovery](discovery.md)) |

A file type with no matching Laravel generator starts as an empty class. Put a `stubs/mod.<type>.stub` in your application to change it.

### Related Files

`relation($id, from: ..., to: ...)` connects two file types. It drives options such as `--factory` and `--policy`, and how one class refers to another.

- `name:` says how the related name derives from the original: `'explicit'` (always named by the caller), or a map of `strip-suffix`, `prefix` and `suffix`, such as `['prefix' => 'Store']`. The related type's own `suffix:` or `fixed:` still applies.
- `scope: ['nested' => 'drop']` stops nested folders carrying over. By default they do: `Models/Archived/Document` relates to `Policies/Archived/DocumentPolicy`.
- `policy:` is `'generate'` (create the related file), `'reference'` (refer to it only) or `'none'`.

### Exclusions

`exclude(...)` marks namespaces or paths inside a root that no file type owns. Mod never places anything there, and discovery skips them.

### Placeholders

Placeholders in `in:` are the layout's dimensions: the ways it groups code. Their order of first appearance is the order of values in `--in`, and each one is also an option of the commands whose folder uses it.

| Placeholder | Meaning | Value |
| --- | --- | --- |
| `{feature}` | one folder | `--feature=Knowledge` or `--in=Knowledge` |
| `{feature?}` | an optional folder | omit it, or `--feature=Knowledge` |
| `{area+}` | one or more folders | `--area=Knowledge.Search` writes to `.../Knowledge/Search/...` |

### Renaming an Option

An option is named after its placeholder. To call it something else, rename it on the layout:

```php
Mod::layout('modules')->placementOption('area');               // mod:model Document --area=Knowledge
Mod::layout('slices')->placementOption('operation', '{slice}'); // --feature=Knowledge --operation=IndexDocument
```

With one placeholder, you don't need to say which one. With several, name the placeholder you are renaming.

### When an Option Name Is Already Taken

Some Laravel commands already have an option that could share a placeholder's name. If your layout writes controllers to `Http/Controllers/{model}`, a `--model` option would clash with `make:controller --model`. Mod keeps Laravel's option, leaves the placement option out and logs a warning. `--in` and the short form still work. Rename the placeholder's option to get it back.

## Adding a Layer

Add a root to the built-in layout from a service provider. Its file types use the same `{domain+}` placeholder, so they take the same `--domain` option and `Knowledge:` prefix:

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
php artisan mod:repository Knowledge:Document
# -> src/Infrastructure/Knowledge/Repositories/DocumentRepository.php

php artisan mod:client Knowledge.Search:Index
# -> src/Infrastructure/Knowledge/Search/Clients/IndexClient.php
```

Add `"Infrastructure\\": "src/Infrastructure/"` to your `composer.json` autoload as well.

A file type with no Laravel generator (`repository` and `client` above) starts as an empty class. To change what it starts as, add `stubs/mod.repository.stub` to your application:

```php
// stubs/mod.repository.stub
<?php

namespace {{ namespace }};

class {{ class }}
{
    //
}
```

To put a Laravel file type in the new layer, declare it there with a `root:` prefix. This moves every job to `src/Infrastructure/<Domain>/Jobs`:

```php
Mod::layout('ddd')->kind('job', in: 'infrastructure:{domain+}/Jobs');
```
