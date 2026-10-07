# Changelog

All notable changes to `mod` will be documented in this file.

## 0.1.0 - YYYY-MM-DD

Initial release.

### Added
- Layouts: name the active layout in `config/mod.php`. Five layouts are built in: `laravel` (the default, placing files exactly like `make:*`), `features`, `slices`, `type-first` and `modules`.
- Define a layout, or extend a built-in one, as one fluent chain: `Mod::layout('name')->root(...)->kind(...)->relation(...)`. Placeholders such as `{feature}`, `{feature?}` and `{group+}` become the placement values.
- `mod:*` generator commands for every kind in the active layout. They are thin subclasses of Laravel's `make:*` commands and take `--in` placement or the `Group:Name` shorthand.
- Companion artifacts follow the layout's relations: a model's factory, a controller's form requests, a listener's event. A whole generation plan is checked for collisions before anything is written.
- Discovery of the service providers, Artisan commands, event listeners and event subscribers a layout places, with `mod:discovery-cache` and `mod:discovery-clear` hooked into `optimize` and `optimize:clear`.
- Protected extension points on the generator commands for packages that build their own command catalog on top of mod.
- Support for PHP 8.3+ and Laravel 11.44+, 12 and 13.
