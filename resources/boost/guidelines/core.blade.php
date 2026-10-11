## Mod

This application uses `tey/mod` to place generated files in its configured layout and discover classes and migrations. Before placing a file, run `php artisan mod:list --json` or the Boost `mod-inventory` tool and read the application's active layout, file types, templates and scaffolds. Prefer the matching `mod:*` generator with explicit placement. Preview it with `--dry-run --json` and inspect the files, warnings and `would_write` before writing. Load the `mod-development` skill for generator options, layouts, templates, discovery, frontend wiring, renaming and the package API reference.

Call `mod-survey` without arguments for actual structure, references and recurring sets. It reads only `base_path()` and local Git metadata, refuses outside paths/symlinks, excludes secrets and runtime data, and identifies vendor references. No contents, writes or network calls. The skill documents its JSON, privacy boundary and limits.
