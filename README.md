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

Laravel lets you organize your app however you like. Mod makes that easy without fighting the framework: start from Laravel's defaults, adopt patterns from modular or domain-driven designs where they help, and keep Laravel's own `make:*` commands and conventions throughout.

Created by [Jasper Tey](https://github.com/jaspertey), building on the lessons from [laravel-ddd](https://github.com/teylabs/laravel-ddd) and generalized for the many different ways developers and their agents organize growing Laravel applications.

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://mod.teylabs.com/evolve-dark.svg">
    <img alt="A terminal watching a Laravel app grow from laravel new: an Actions folder, then modules, then a versioned API in src beside app" src="https://mod.teylabs.com/evolve-light.svg" width="600">
  </picture>
</p>

> [!NOTE]
> Mod is pre-1.0. Minor releases may change the API until 1.0.

- [Six built-in layouts](https://mod.teylabs.com/basics/layouts), including modular monolith and DDD, or your own
- [Laravel's own generators](https://mod.teylabs.com/basics/generating-files) for every file type, writing into your layout, with related files alongside
- [Templates and scaffolds](https://mod.teylabs.com/going-further/scaffolds): turn your own patterns into commands, then [rename a whole cluster](https://mod.teylabs.com/going-further/renaming) when the feature changes
- [Auto-discovery](https://mod.teylabs.com/basics/auto-discovery) of providers, commands, listeners, migrations, factories and policies
- **Agent-friendly**: your structure lives in code, so coding agents can look it up and preview every write. See [Working with AI agents](#working-with-ai-agents).

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

## What Mod Does

| | Try it | Learn more |
| --- | --- | --- |
| Layouts | `'layout' => 'modules'` in `config/mod.php` | [Layouts](https://mod.teylabs.com/basics/layouts) |
| Generators | `mod:model Knowledge:Document --all` | [Generating files](https://mod.teylabs.com/basics/generating-files) |
| Auto-discovery | providers, commands, listeners, migrations, factories and policies, found wherever they live | [Auto-discovery](https://mod.teylabs.com/basics/auto-discovery) |
| Your own generators | `mod:template tool`, then `mod:tool Knowledge:Search` | [Custom generators](https://mod.teylabs.com/going-further/custom-generators) |
| Scaffolds | several related files from one recipe | [Scaffolds](https://mod.teylabs.com/going-further/scaffolds) |
| Module routes and views | `Mod::routes()`, `view('knowledge::documents.show')` | [Routes](https://mod.teylabs.com/going-further/routes) |
| Renames | `mod:rename Knowledge:Document Knowledge:Article` | [Renaming](https://mod.teylabs.com/going-further/renaming) |

## Working with AI Agents

Conventions written in a prompt drift as an app grows. Mod keeps your structure in code, where coding agents can ask for it:

- `php artisan mod:list --json` tells an agent where every kind of file goes.
- Every command that writes files previews its plan with `--dry-run --json`.
- Mod ships a [Laravel Boost](https://laravel.com/docs/boost) guideline, skill and read-only MCP tools. Run `php artisan boost:install` and choose `tey/mod`.

See [Agents](https://mod.teylabs.com/going-further/agents).

## FAQ

### Can I Add Mod to an Existing App?

Yes. Mod doesn't move existing files, and `make:*` keeps working. Start on the `laravel` layout and switch when you're ready.

### When to Choose Mod over the Alternatives

There are several good ways to organize a Laravel app. Pick by what you need:

- **[nwidart/laravel-modules](https://github.com/nWidart/laravel-modules)**: modules you can switch on and off, each with its own config and assets. The most established option.
- **[InterNACHI/modular](https://github.com/InterNACHI/modular)**: each module as its own Composer package, close to plain Laravel, with Blade components and translations loaded per module.
- **[laravel-ddd](https://github.com/teylabs/laravel-ddd)**: mod's precursor, for domain-driven design with nothing else to learn. Mod's `ddd` layout keeps the same folders if you outgrow it.
- **Mod**: your own structure, whether feature folders, vertical slices, DDD, a modular monolith or a mix, with Laravel's own `make:*`, generators for your team's patterns, and a structure your coding agents can read.

Mod isn't the right fit yet if you need a stable 1.0 API, per-module translations or assets, or modules you can switch on and off at runtime.

## Documentation

Everything else, including [configuration](https://mod.teylabs.com/reference/configuration), [production caching](https://mod.teylabs.com/basics/auto-discovery#caching-discovery-in-production) and the [command reference](https://mod.teylabs.com/reference/commands), is at [mod.teylabs.com](https://mod.teylabs.com).

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
