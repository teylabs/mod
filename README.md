<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://mod.teylabs.com/logo-dark.svg">
    <img alt="" src="https://mod.teylabs.com/logo-light.svg" height="36" align="top">
  </picture>
  Mod: Modular Development Toolkit for Laravel
</h1>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/teylabs/mod/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/mod/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tey/mod.svg?style=flat-square)](https://packagist.org/packages/tey/mod)

Mod is a lightweight toolkit for modular development in Laravel, for you and your coding agents.

Organizing an app by module, feature or domain usually means fighting Laravel's defaults: `make:*` writes to `app/Models`, and every module's providers, commands and listeners need registering by hand. Mod makes Laravel's own tools work in the structure you choose. Pick or extend a common layout like DDD or a modular monolith, or create your own.

Created by [Jasper Tey](https://github.com/jaspertey), building on the lessons from [laravel-ddd](https://github.com/teylabs/laravel-ddd) and generalized for the many different ways developers and their agents organize growing Laravel applications.

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

- **Built for AI agents**. Your layout, templates and scaffolds tell agents where everything goes, and every generator previews its plan as JSON before writing. See [Working with AI agents](#working-with-ai-agents).
- [Six built-in layouts](#choosing-a-layout), including modular monolith and DDD, or your own
- [Laravel's own generators](#generating) for every file type, writing into your layout, with related files alongside
- [Templates and scaffolds](#scaffolds): turn your own patterns into commands, then [rename a whole cluster](#renaming-clusters) when the feature changes
- [Auto-discovery](#auto-discovery) of providers, commands, listeners, migrations, factories and policies

The full documentation is at [mod.teylabs.com](https://mod.teylabs.com).

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

[Layouts](https://mod.teylabs.com/basics/layouts) shows every layout's folders, and [Custom Layouts](https://mod.teylabs.com/going-further/custom-layouts) covers extending one or defining your own.

### Generating

Each file type in your layout has a `mod:*` command, built on Laravel's own `make:*` with the same arguments and options. Related files land in the same module:

```bash
php artisan mod:model Knowledge:Document --all
php artisan mod:listener Knowledge:GenerateEmbeddings --event=DocumentUploaded
```

The short form `Knowledge:Document` names the group, here a module; layouts with more than one level take `--in=Knowledge/IndexDocument`. `php artisan mod:list` shows what the active layout can generate. `make:*` is untouched. See [Generating Files](https://mod.teylabs.com/basics/generating-files).

### Auto-Discovery

Providers, Artisan commands, event listeners and event subscribers anywhere your layout places them are registered with Laravel, and module migrations run with `php artisan migrate`. A model finds its factory and policy with no registration. See [Auto-Discovery](https://mod.teylabs.com/basics/auto-discovery).

### Your Own File Types

Add a file type to any layout with one line in a service provider:

```php
Mod::layout('modules')->generates('validator', in: 'Modules/{module}/Validators', suffix: 'Validator');
```

```bash
php artisan mod:validator Knowledge:Upload
# -> app/Modules/Knowledge/Validators/UploadValidator.php
```

Or start from a template: `php artisan mod:template tool` creates `stubs/mod/@module/Tools/tool.stub`, and `mod:tool` writes from it. See [Custom Generators](https://mod.teylabs.com/going-further/custom-generators).

### Scaffolds

A scaffold is a recipe of several file types generated together:

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;

Mod::scaffold('document', fn (Scaffold $s) => $s
    ->makes('model', as: 'model')
    ->makes('controller', name: '{name}Controller', as: 'controller',
        options: ['--resource']));
```

```bash
php artisan mod:document Knowledge:Document --no-interaction
```

Recipes can ask questions, repeat parts, include frontend pages and grow later through anchored inserts. See [Scaffolds](https://mod.teylabs.com/going-further/scaffolds) and [Frontend](https://mod.teylabs.com/going-further/frontend).

### Renaming Clusters

When a feature's name changes, `mod:rename` moves every member of a scaffolded cluster and updates its PHP and frontend references:

```bash
php artisan mod:rename Knowledge:Document Knowledge:Article --scaffold=document
```

It shows the plan and asks first, then stages the changes in git for you to review and commit. See [Renaming](https://mod.teylabs.com/going-further/renaming).

### Working with AI Agents

Agents drift. Each session works out again where a model, a page or a listener belongs. Conventions written in a prompt or `CLAUDE.md` go stale as the app grows, and five sessions later the same feature has five shapes. Mod keeps the structure in code, where an agent can ask for it and can't get it wrong:

- **It knows where things go.** `php artisan mod:list --json` describes the layout: its groups, file types, templates, scaffolds, routes, views and frontend paths. Agents read it before writing anything.
- **Generators place files, agents don't.** `mod:model Billing:Invoice` puts the model, its factory and its migration where your layout says, the same way every time.
- **Previews before writes.** Every command that writes files accepts `--dry-run --json` and prints exactly what it would create or change, without writing or prompting. An agent (or its reviewer) checks the plan, then runs the command.
- **Your patterns become commands.** A template or scaffold captures how your team builds a feature, so an agent generates the same shape you would, instead of improvising.
- **Names stay aligned.** When a feature is renamed, `mod:rename` moves every member and updates the references, so old names don't linger for the next session to copy.
- **Laravel Boost support.** Mod ships a [Laravel Boost](https://laravel.com/docs/boost) guideline and a `mod-development` skill, plus read-only `mod-inventory` and `mod-plan` MCP tools. With Boost installed, run `php artisan boost:install`, choose `tey/mod` among the third-party packages, and select its guideline and skill. Run `php artisan boost:update` after updating mod.

### Self-Contained Modules

In the `modules` layout, everything a feature needs lives in one folder: models, migrations, factories, policies, controllers, events, listeners, views, pages and routes. Load every module's routes with one call in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    then: fn () => \Tey\Mod\Facades\Mod::routes(),
)
```

Copy a module folder to another project using the same layout, run `php artisan mod:bases` once, and it keeps working. See [Self-Contained Modules](https://mod.teylabs.com/going-further/self-contained-modules) and [Module Routes](https://mod.teylabs.com/going-further/routes).

## Configuration

| Option | Default | Description |
| --- | --- | --- |
| `layout` | `'laravel'` | The active layout: a built-in name or one you define |
| `commands` | `true` | Register the `mod:*` commands |
| `generators` | `[]` | Replace the command behind a file type, by type |
| `bases` | `null` each | The class DTOs, view models, value objects and actions extend, by file type |
| `bases_path` | `'app/Support'` | Where generated base classes go |
| `discovery.enabled` | `true` | Register discovered providers, commands, listeners and subscribers; `false` also turns off factory and policy lookup |
| `discovery.file_types` | `[]` | Discover more file types by file type id, or `false` to skip one, such as `'migration' => false` |
| `discovery.cache` | `'bootstrap/cache/mod-discovery.php'` | Where the discovery cache is written |
| `discovery.on_stale_cache` | `'scan'` | `'scan'` ignores an outdated cache with a warning; `'fail'` stops the app booting |
| `discovery.factories` | `true` | Find factories for models the layout places |
| `discovery.policies` | `true` | Find policies for models the layout places |

## Production

`php artisan optimize` caches discovery, and `php artisan optimize:clear` clears it:

```bash
php artisan mod:cache   # also run by optimize
php artisan mod:clear   # also run by optimize:clear
```

Like Laravel's own caches, the discovery cache doesn't pick up new classes. Run `php artisan optimize:clear` after adding a provider, command or listener while it exists.

`mod:cache` counts the files it found but didn't register as "rejected", and says why. Plain classes such as `app/Models/User.php` need nothing; [Caching](docs/discovery.md#caching) explains each reason.

## FAQ

### Can I Add Mod to an Existing App?

Yes. Mod doesn't move or change existing files, and `make:*` keeps working. Start on the `laravel` layout, which places files like `make:*`, and switch layouts when you're ready. Classes already in Laravel's default folders keep working as before.

### Do Folders Outside `app/` Need Autoloading?

Yes. A layout that writes outside `app/`, such as `ddd`'s `src/Domain`, needs a PSR-4 entry in your `composer.json` autoload, as shown in [The DDD Layout](https://mod.teylabs.com/basics/layouts#the-ddd-layout). Run `php artisan mod:autoload` to add missing mappings and reload Composer.

### How Is This Different from nwidart/laravel-modules or InterNACHI/modular?

| | [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules) | [InterNACHI/modular](https://github.com/InterNACHI/modular) | Mod |
| --- | --- | --- | --- |
| Structure | `Modules/<Module>/` | `app-modules/<module>/` | a built-in layout or your own |
| Per module | config, plus a Composer merge plugin | a `composer.json` | a folder |
| Generators | `module:make-*` | `make:*` with `--module=` | `mod:*`, built on `make:*` |
| Discovered | providers | providers, commands, migrations, factories, policies, listeners, Blade components, translations | providers, commands, listeners, subscribers, migrations, factories, policies, plus module routes (one `Mod::routes()` call) and view namespaces |

Both are mature. nwidart/laravel-modules also enables and disables modules at runtime and handles per-module assets. InterNACHI/modular also loads Blade components and translations. Mod doesn't load per-module translations or assets; module routes load with one `Mod::routes()` call, as in [Module Routes](https://mod.teylabs.com/going-further/routes). Choose mod to keep a structure you already have, or to use DDD, feature folders or vertical slices instead of one module format.

## Documentation

The full documentation, with guides and a complete reference, is at [mod.teylabs.com](https://mod.teylabs.com). The pages below cover the same ground in this repository.

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
