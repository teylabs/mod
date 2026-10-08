# Mod: Modular Development Toolkit for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)

Mod is a lightweight toolkit for modular development in Laravel.

Organizing an app by module, feature or domain usually means fighting Laravel's defaults: `make:*` writes to `app/Models`, and every module's providers, commands and listeners need registering by hand. Mod makes Laravel's own tools work in the structure you choose. Pick or extend a common layout like DDD or a modular monolith, or create your own.

Created by [Jasper Tey](https://github.com/jaspertey), building on the lessons from [laravel-ddd](https://github.com/teylabs/laravel-ddd) and generalized for the many different ways developers organize their growing Laravel applications.

```bash
php artisan mod:model Knowledge:Document -mf   # with 'layout' => 'modules'
```

```text
app/Modules/Knowledge/
├── Database/
│   ├── Factories/
│   │   └── DocumentFactory.php
│   └── Migrations/
│       └── 2026_10_08_120000_create_documents_table.php
└── Models/
    └── Document.php
```

`php artisan migrate` runs that migration, and `Document::factory()` finds that factory.

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

### Laravel Boost

Mod ships a [Laravel Boost](https://laravel.com/docs/boost) guideline and a `mod-development` skill, so AI agents check your layout and place files with `mod:*` generators. With Boost installed in your app, run `php artisan boost:install`, choose `tey/mod` among the third-party packages, and select its guideline and skill. Run `php artisan boost:update` after updating mod.

## Quick Start

Choose a layout in `config/mod.php`:

```php
// config/mod.php
'layout' => 'modules',
```

Generate a model with its migration and factory, then migrate:

```bash
php artisan mod:model Knowledge:Document -mf
php artisan migrate
# -> runs 2026_10_08_120000_create_documents_table from app/Modules/Knowledge/Database/Migrations
```

## Usage

### Choosing a Layout

Six layouts are built in. The default, `laravel`, places files exactly like `make:*`, so you can install mod first and switch layouts later.

| Layout | Organizes code as | `mod:model Knowledge:Document` writes |
| --- | --- | --- |
| `laravel` | Laravel's own folders | `app/Models/Document.php` (no `Knowledge:`) |
| `modules` | a modular monolith: one folder per module | `app/Modules/Knowledge/Models/Document.php` |
| `features` | feature folders | `app/Features/Knowledge/Models/Document.php` |
| `slices` | vertical slices: features, each split into slices | `app/Knowledge/Models/Document.php` |
| `type-first` | Laravel's folders, with an optional sub-folder | `app/Models/Knowledge/Document.php` |
| `ddd` | domain-driven design, as in laravel-ddd | `src/Domain/Knowledge/Models/Document.php` |

Each tree below is the result of `php artisan mod:model Knowledge:Document --all` in a fresh app.

<details>
<summary><code>modules</code></summary>

```text
app/Modules/Knowledge/
├── Controllers/
│   └── DocumentController.php
├── Database/
│   ├── Factories/
│   │   └── DocumentFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_documents_table.php
│   └── Seeders/
│       └── DocumentSeeder.php
├── Models/
│   └── Document.php
├── Policies/
│   └── DocumentPolicy.php
└── Requests/
    ├── StoreDocumentRequest.php
    └── UpdateDocumentRequest.php
```

</details>

<details>
<summary><code>features</code></summary>

```text
app/Features/Knowledge/
├── Database/
│   ├── Factories/
│   │   └── DocumentFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_documents_table.php
│   └── Seeders/
│       └── DocumentSeeder.php
├── Http/
│   ├── Controllers/
│   │   └── DocumentController.php
│   └── Requests/
│       ├── StoreDocumentRequest.php
│       └── UpdateDocumentRequest.php
├── Models/
│   └── Document.php
└── Policies/
    └── DocumentPolicy.php
```

</details>

<details>
<summary><code>slices</code></summary>

A slice holds one operation's classes, each with a fixed name. This tree is the result of `mod:model Knowledge:Document -mf`, then `mod:handler`, `mod:request` and `mod:message` with `--in=Knowledge/IndexDocument`:

```text
app/Knowledge/
├── IndexDocument/
│   ├── Command.php
│   ├── Handler.php
│   └── Request.php
├── Database/
│   ├── Factories/
│   │   └── DocumentFactory.php
│   └── Migrations/
│       └── 2026_10_08_120000_create_documents_table.php
└── Models/
    └── Document.php
```

</details>

<details>
<summary><code>type-first</code></summary>

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── Knowledge/
│   │       └── DocumentController.php
│   └── Requests/
│       └── Knowledge/
│           ├── StoreDocumentRequest.php
│           └── UpdateDocumentRequest.php
├── Models/
│   └── Knowledge/
│       └── Document.php
└── Policies/
    └── Knowledge/
        └── DocumentPolicy.php
database/
├── factories/
│   └── Knowledge/
│       └── DocumentFactory.php
├── migrations/
│   └── Knowledge/
│       └── 2026_10_08_120000_create_documents_table.php
└── seeders/
    └── Knowledge/
        └── DocumentSeeder.php
```

</details>

<details>
<summary><code>ddd</code></summary>

```text
app/Modules/Knowledge/
├── Controllers/
│   └── DocumentController.php
└── Requests/
    ├── StoreDocumentRequest.php
    └── UpdateDocumentRequest.php
src/Domain/Knowledge/
├── Database/
│   ├── Factories/
│   │   └── DocumentFactory.php
│   ├── Migrations/
│   │   └── 2026_10_08_120000_create_documents_table.php
│   └── Seeders/
│       └── DocumentSeeder.php
├── Models/
│   └── Document.php
└── Policies/
    └── DocumentPolicy.php
```

</details>

[docs/layouts.md](docs/layouts.md) lists every folder of every built-in layout.

### Generating

Each file type in your layout has a `mod:*` command. It is Laravel's own `make:*` command underneath, with the same arguments and options, so the generated code is what Laravel would write:

```bash
php artisan mod:event Knowledge:DocumentUploaded
# -> app/Modules/Knowledge/Events/DocumentUploaded.php

php artisan mod:listener Knowledge:GenerateEmbeddings --event=DocumentUploaded
# -> app/Modules/Knowledge/Listeners/GenerateEmbeddings.php (imports App\Modules\Knowledge\Events\DocumentUploaded)
```

`php artisan list mod` shows every command your layout has. `make:*` is untouched and keeps writing to Laravel's default folders.

#### Related Files

Options such as `-m`, `-f`, `--policy`, `--requests` and `--all` create the related files in the same module, as in the trees above. The model links its factory, so `Document::factory()` works wherever the factory lives.

`mod:*` checks every file it is about to write before writing any of them. When one already exists, it prints an error and writes nothing.

### Placement

The `laravel` layout puts files where `make:*` does. The other layouts group your code, so each command also needs to know which group a file belongs to.

Each way a layout groups code is a **dimension**. `modules` has one: the module. `slices` has two: the feature, and the slice inside it. A layout's folders show each one as a placeholder, such as `{module}` in `app/Modules/{module}/Models`, and you give it a value, such as `Knowledge`. These three commands do the same thing:

```bash
php artisan mod:model Document --module=Knowledge   # an option named after the placeholder
php artisan mod:model Document --in=Knowledge       # every value at once
php artisan mod:model Knowledge:Document            # the short form: value, colon, class name
```

When there are two values, `--in` and the short form take them in order, separated by `/`:

```bash
php artisan mod:handler Handler --feature=Knowledge --slice=IndexDocument
php artisan mod:handler Handler --in=Knowledge/IndexDocument
# -> app/Knowledge/IndexDocument/Handler.php
```

| Layout | Options | Values |
| --- | --- | --- |
| `modules` | `--module` | `Knowledge` |
| `features` | `--feature` | `Knowledge` |
| `slices` | `--feature`, `--slice` | `Knowledge`, `IndexDocument` |
| `type-first` | `--feature` (optional) | `Knowledge`, or none for `app/Models/Document.php` |
| `ddd` | `--domain` (one or more folders) | `Knowledge`, or `Knowledge.Search` for `src/Domain/Knowledge/Search` |

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

php artisan mod:dto Knowledge:DocumentData
# -> src/Domain/Shared/Data/DataTransferObject.php (created once)
# -> src/Domain/Knowledge/Data/DocumentData.php

php artisan mod:action Knowledge:IndexDocument
# -> src/Domain/Knowledge/Actions/IndexDocument.php
```

`mod:value` and `mod:view-model` complete the set, and laravel-ddd's command names work as aliases (`mod:data`, `mod:value-object`, `mod:viewmodel`). See [docs/layouts.md](docs/layouts.md#the-ddd-layout) for every folder and for adding a layer such as `src/Infrastructure`.

### Starter Stubs and Stub Variants

DTOs, value objects, view models and actions start as plain Laravel-style classes. When [spatie/laravel-data](https://github.com/spatie/laravel-data), [spatie/laravel-view-models](https://github.com/spatie/laravel-view-models) or [lorisleiva/laravel-actions](https://github.com/lorisleiva/laravel-actions) is installed, mod uses it instead:

```bash
php artisan mod:dto Knowledge:DocumentData
# ->  INFO  Using spatie/laravel-data (installed).
```

A base class mod writes into your app is yours from then on: mod never overwrites it. [Stub Variants](docs/layouts.md#stub-variants) covers each variant, your own base classes and published stubs.

### Auto-Discovery

Providers, Artisan commands, event listeners and event subscribers anywhere your layout places them are registered with Laravel. The listener from [Generating](#generating) needs no registration:

```bash
php artisan event:list --event=DocumentUploaded
# -> App\Modules\Knowledge\Events\DocumentUploaded
# ->   ⇂ App\Modules\Knowledge\Listeners\GenerateEmbeddings@handle
```

A listener Laravel's own event discovery already registers is never registered twice. Migration folders such as `app/Modules/Knowledge/Database/Migrations` are added to Laravel's migrator, so `migrate`, `migrate:rollback` and `migrate:status` include them. A model finds its factory and policy through the layout, with no registration:

```php
use App\Modules\Knowledge\Models\Document;
use Illuminate\Support\Facades\Gate;

Document::factory();                 // App\Modules\Knowledge\Database\Factories\DocumentFactory
Gate::getPolicyFor(Document::class); // App\Modules\Knowledge\Policies\DocumentPolicy
```

[docs/discovery.md](docs/discovery.md) covers what is discovered where.

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
php artisan mod:validator Knowledge:Upload
# -> app/Modules/Knowledge/Validators/UploadValidator.php
```

It starts as an empty class. To start from your own stub, add `stubs/mod.validator.stub` to your app, using `{{ namespace }}` and `{{ class }}` where the class's namespace and name go.

### Self-Contained Modules

Keep everything a feature needs in one folder, so you can copy it to the next project. The `modules` layout already keeps models, migrations, factories, actions, events, listeners and jobs inside each module. Add the file types you use most:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

public function boot(): void
{
    // Everything a feature needs, in one folder you can copy to the next project.
    Mod::layout('modules')
        ->kind('view-model', in: 'Modules/{module}/ViewModels', label: 'View model')
        ->kind('value-object', in: 'Modules/{module}/ValueObjects', command: 'mod:value', label: 'Value object');
}
```

Then build two modules, Knowledge and Agents:

```bash
php artisan mod:model Knowledge:Document -mf --controller --resource --requests
php artisan mod:action Knowledge:IndexDocument
php artisan mod:event Knowledge:DocumentUploaded
php artisan mod:listener Knowledge:GenerateEmbeddings --event=DocumentUploaded
php artisan mod:view-model Knowledge:ShowDocument

php artisan mod:model Agents:Conversation -m
php artisan mod:action Agents:AnswerQuestion
php artisan mod:value Agents:TokenUsage
php artisan mod:job Agents:GenerateReply
```

<details>
<summary>The resulting <code>app/Modules/</code> folder</summary>

```text
app/Modules/
├── Agents/
│   ├── Actions/
│   │   └── AnswerQuestion.php
│   ├── Database/
│   │   └── Migrations/
│   │       └── 2026_10_08_120001_create_conversations_table.php
│   ├── Jobs/
│   │   └── GenerateReply.php
│   ├── Models/
│   │   └── Conversation.php
│   └── ValueObjects/
│       └── TokenUsage.php
└── Knowledge/
    ├── Actions/
    │   └── IndexDocument.php
    ├── Controllers/
    │   └── DocumentController.php
    ├── Database/
    │   ├── Factories/
    │   │   └── DocumentFactory.php
    │   └── Migrations/
    │       └── 2026_10_08_120000_create_documents_table.php
    ├── Events/
    │   └── DocumentUploaded.php
    ├── Listeners/
    │   └── GenerateEmbeddings.php
    ├── Models/
    │   └── Document.php
    ├── Requests/
    │   ├── StoreDocumentRequest.php
    │   └── UpdateDocumentRequest.php
    └── ViewModels/
        └── ShowDocument.php
```

</details>

Each module is one folder. Copy either into another project that uses the same layout, and its migrations, listeners and factories come with it.

To define a layout from scratch instead, see [Defining a Layout](docs/layouts.md#defining-a-layout).

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
