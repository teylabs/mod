# Renaming and moving a cluster

`mod:rename` renames the members of an application-owned scaffold together, preserves edited bodies and stages supported reference edits for review. It can also move that cluster to another configured group. Start with a committed application and the recipe that describes the complete existing cluster.

## Preview, confirm and review

For the ten-member recipe below, preview a rename (R1) or group move (R2):

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=rename-crud --dry-run
php artisan mod:rename Inventory:Widget Catalog:Widget --scaffold=rename-crud --dry-run --json
```

Execution requires a clean Git working tree and index, including unignored untracked files, and a configured destination group. Commit or stash your own work before retrying. Preview still explains blockers and exits 0 with `would_write: false`; attempted execution of a blocked plan exits 1. Preview never prompts, moves files, refreshes the index, creates a journal or applies recovery.

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=rename-crud
# Final confirmation: Rename this cluster and stage the changes? [No]
```

After reviewing the plan, an agent supplies confirmation explicitly:

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=rename-crud --yes --no-interaction
```

The result is a staged diff containing member moves, supported reference edits and any selected new migration. Review `git diff --cached` and run application checks before committing. Mod never commits for you. Cancellation exits 0 without writes. Execution always computes a fresh plan; an earlier preview is not an execution token.

## Declare the existing members

A recipe is required even when only one is registered. In a terminal you can select it; without one, pass `--scaffold`. This app recipe declares every member explicitly, with the controller and page variants from [Frontend Files](https://mod.teylabs.com/going-further/frontend):

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;

Mod::scaffold('rename-crud', fn (Scaffold $s) => $s
    ->makes('model', as: 'model')
    ->makes('controller', name: '{name}Controller', as: 'controller', stub: 'inertia-crud')
    ->makes('request', name: 'Store{name}Request', as: 'store', stub: 'crud')
    ->makes('request', name: 'Update{name}Request', as: 'update', stub: 'crud')
    ->makes('resource', name: '{name}Resource', as: 'resource')
    ->makes('policy', name: '{name}Policy', as: 'policy')
    ->makes('page', name: '{name}/Index', as: 'indexPage', stub: 'crud-index')
    ->makes('page', name: '{name}/Create', as: 'createPage', stub: 'crud-form')
    ->makes('page', name: '{name}/Edit', as: 'editPage', stub: 'crud-form')
    ->makes('page', name: '{name}/Show', as: 'showPage', stub: 'crud-show'));
```

The request variants above need the `model` alias. The controller's resource/model/indexPage/editPage aliases match its variant. Rename does not ship this recipe or install a frontend. Native generating options such as model `--all`, `--migration` and `--factory` are refused for rename: declare those existing companions as explicit recipe members. A historical migration member resolves by its suffix in the compiled directory and is retained, never recreated.

The same effective source recipe resolves both identities. Destination-specific template/type/extension drift blocks. Missing members, unmatched candidates, declaration drift, malformed PHP, collisions, duplicate destinations, escaping or symlink paths and unsafe case-only moves block before writing. Resolve ownership in the recipe; there is no `--force`, skip, exclusion or overwrite bypass.

## Grown parts, shared files and manual edits

Pass every original answer, including defaults and parts added later:

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=resource-tabs --tabs=Overview --tabs=Details --tabs=Notes --dry-run --json
```

Nested answers use the original qualified part path, for example `--answer='tab.Overview.label="Overview"'` or `--answer='tab.Overview.sections=["Notes"]'`. Explicit empty history can use `--answer='tabs=[]'`. Recipe-defined flags are not built-in product recipes. Unknown, unused or incomplete answers block. A name prefix does not prove ownership, and recursive child rebasing is conservatively refused where members cannot be resolved uniquely. Rename never regenerates templates or replays anchored inserts.

Stable `existing: 'keep'` and shared members remain at their paths; supported references inside them can still change. A kept member whose identity would change blocks. `ungrouped: true` describes placement: an ungrouped member can move when its identity changes. Edited method bodies, comments, variables, user copy and line endings remain intact; the original declaration must still match the recipe.

## Placement and scan boundaries

Both identities come from the compiled layout. A group move leaves its provider, routes and unrelated files in place: it moves a cluster, not an entire module. It does not upgrade folders or edit Composer autoload mappings. The intentional `ddd` defaults place Controllers, Requests and Middleware outside `Http/`, with frontend, views and routes opt-in.

Scans cover compiled roots, `tests`, `app`, `bootstrap`, `config`, `resources` and `routes`, plus root `lang` when present. Overlapping roots are deduplicated. Dependency, build, cache, Git metadata and generator-template paths are excluded by the package's `resources/rename/excluded-paths.json`. Historical migrations are immutable, including references to old classes. Runtime code outside these roots is outside the analysis guarantee.

## Supported references and manual review

PHP tokens bind names through each original namespace/import table. Mapped declarations, imports, grouped imports, types, attributes, inheritance, traits, `new`, `instanceof` and static references change together. Explicit aliases preserve their spelling. Import conflicts, malformed output and ambiguous declarations block. Only exact mapped Inertia/view literals in correctly bound calls change.

| Source | Application parser capability | Behaviour |
| --- | --- | --- |
| JS/MJS/CJS, TS, React JSX/TSX | `@babel/parser` 7.29.x with the appropriate plugins | Static import/export/dynamic-import literals |
| Vue scripts and templates | `@vue/compiler-sfc` 3.5.x; Babel 7.29.x for scripts | Static imports and mapped static component identities |
| Blade | PHP lexer, independent of Node | Supported literal include/extends/component/each directives and anonymous-component tags |
| CSS URLs | Detection only | Located checklist |

These are accepted series; tested exact versions and toolchains are recorded in [verification evidence](https://github.com/teylabs/mod-docs/tree/main/verify). Parsers must resolve inside the app's installed `node_modules`. Vite alone does not establish parser availability. Mod loads its shipped helper from Composer, installs nothing and does not evaluate arbitrary Vite configuration or custom aliases.

Canonical `@modules/`, app `@/` and relative imports retain their form and original binding, including neighbour imports from moved files. Missing/unsupported parsers, malformed frontend syntax, escaped/computed imports, ambiguous paths, Vue preprocessors/external scripts/custom blocks and unsupported identities fall back per file: reference bytes stay unchanged and the plan reports original `file`, `line`, `after_file` and a known `suggestion`. File moves and safe PHP edits can still proceed. Apply the reported manual fixes before claiming working frontend wiring.

Route URIs/names, translation/configuration strings, explicit database names, computed identities, class strings and persisted serialization values remain review items. Checklist entries are advisory and cannot prove that all runtime or external data references were found.

## Optional table migration

An inferred Eloquent table can change with the model basename: Widget uses `widgets`, Gadget would use `gadgets`. A literal `$table = 'widgets'` stays unchanged; a group-only move with the same basename offers no table rename. Mod reports foreign-key, binding and serialization risks without querying a database or inserting `$table`.

Interactive execution offers `Create a reversible rename-table migration from widgets to gadgets?`, default No, before the final plan. Agents select it explicitly:

```bash
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=rename-crud --table-migration --dry-run --json
php artisan mod:rename Inventory:Widget Inventory:Gadget --scaffold=rename-crud --table-migration --yes --no-interaction
```

With the R12 clock fixed at 9 October 2026, 16:30, the new candidate is `app/Modules/Inventory/Database/Migrations/2026_10_09_163000_rename_widgets_to_gadgets_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('widgets', 'gadgets');
    }

    public function down(): void
    {
        Schema::rename('gadgets', 'widgets');
    }
};
```

The candidate appears in `files` and is staged with the rename. No migration is executed and historical source stays byte-identical. Review and run it separately through your application's deployment process. Custom parents, dynamic table logic, unresolved traits, custom connections and unsupported app-custom native migration creators prevent exact inference/planning: selected generation refuses. Omit the flag and create a reviewed migration separately when necessary.

## Inspect and recover an interruption

Ordinary failures restore transaction-owned paths, bytes, permissions and index entries. Successful restoration says `All changes were rolled back. Nothing was renamed.` Incomplete restoration says `Rollback incomplete; recovery required`, names the journal/conflict and preserves outside edits.

A journal blocks a fresh rename. Inspect it first:

```bash
php artisan mod:rename --recover --dry-run --json
```

Then restore after review:

```bash
php artisan mod:rename --recover
php artisan mod:rename --recover --yes --no-interaction
```

Recovery takes no cluster arguments, recipe answers, `--scaffold` or `--table-migration`. Interactive confirmation is `Restore this interrupted rename?`, default No. Inspection is read-only and takes no lock. Recovery compares owned bytes, permissions, paths and index state before restoring; unrelated working, untracked and staged changes survive. Preserve reported outside edits separately and resolve the conflict before retrying the same recovery command. Never delete the journal to bypass recovery.

Git resolves the worktree-specific journal at `<git-directory>/mod-rename/journal.json` and persistent lock at `<git-directory>/mod-rename/lock`. Linked worktrees have separate state. JSON recovery adds `recovery` with `journal`, `phase`, `operations` and `checkpoint`. Repeated recovery with no journal succeeds without changes; completed staged renames interrupted during metadata cleanup are preserved.

## Explicit limits

Execution requires the Laravel project to be the Git worktree root; nested apps are refused. Existing generation commands keep their previous no-move behaviour. There is no saved-plan application, automatic stash, commit, module move or layout upgrade.

Recovery is tested for process termination. Machine power-loss guarantees are not claimed, particularly where PHP cannot sync directories on Windows. Journals are trusted Git-owned metadata with corrupt-state guards. Compare-before-restore protects observed outside changes; it does not serialize arbitrary external editors. Filesystems unable to provide the required exclusive index-restoration hard link leave the journal for recovery rather than using destructive fallback.

See [Agents](https://mod.teylabs.com/going-further/agents#rename-plans) for the complete R14 JSON and read-only tool contract, and [Commands](renaming.md#command-options) for every shared flag.

## Command options


`mod:rename <old> <new>` renames an explicitly selected recipe-owned cluster through its compiled layout. See [Renaming and moving](renaming.md) before execution.

| Option | Behaviour |
| --- | --- |
| `--scaffold=<recipe>` | Selects the effective recipe; mandatory for ordinary planning, or selected interactively |
| Recipe question/part flags | Supply the complete historical answers; repeated list flags include grown parts |
| `--answer=<qualified-key=JSON>` | Supplies nested/reserved answers using original part paths |
| `--dry-run` | Read-only preview; no prompts, writes, staging or journal |
| `--json` | Machine plan; requires `--dry-run` |
| `--table-migration` | Selects complete reversible migration candidates when exact inference/planning is available; never executes them |
| `--yes` | Accepts final confirmation after the fresh plan |
| `--no-interaction` | Requires explicit decisions; use with `--yes` for approved execution |
| `--recover` | Recovers the worktree journal without cluster arguments or recipe/table flags |

`--recover --dry-run --json` inspects without restoring. `--recover --yes --no-interaction` restores after review and refuses conflicting outside changes. Journals live in the actual worktree Git directory under `mod-rename/`. Ordinary execution requires a clean Git tree/index and a configured target. The staged result is never committed. There is no `--force`, overwrite, automatic stash or `--apply-plan`.
