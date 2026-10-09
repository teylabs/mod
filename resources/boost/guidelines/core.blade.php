## Mod

This application uses `tey/mod` to place generated files in its own layout and to discover its providers, commands, listeners and migrations. Before placing a file, check the configured layout, not the package defaults: `'layout'` in `config/mod.php`, any `Mod::layout()` calls in service providers, and the Composer PSR-4 mappings. Prefer the matching `mod:*` generator over `make:*`, and give its placement explicitly: `Knowledge:Document`, `--in=Knowledge`, or the layout's own option such as `--module=Knowledge`. Run `php artisan list mod` to see which generators this layout has. Use the `mod-development` skill when generating files or changing layouts, stubs, base classes or discovery.

After choosing `ddd` or adding a layout root outside `app/`, run `php artisan mod:autoload` to add missing Composer PSR-4 mappings and reload the autoloader. Use `--dry-run` to preview the entries or `--no-dump` when a script runs Composer itself.
