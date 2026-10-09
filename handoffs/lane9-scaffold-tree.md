# Lane 9 scaffold tree handoff

Branch: `0.2/scaffold-tree`, worktree `/Users/jasper/Dev/projects/mod-lane9`, based on `ad151f9`.

## L6: public read-only registry

```php
$registry = app(\Tey\Mod\Scaffolds\ScaffoldRegistry::class);
$nodes = $registry->nodes();
$problems = $registry->problems();
```

`nodes()` returns a fresh array keyed by dot path. Each row contains:

- `key`: the same dot path.
- `source`: `app` or the registering package name, for example `acme/tabs-kit`.
- `from`: the display provenance, including `app (overrides acme/tabs-kit)`.
- `members`: alias-keyed arrays with `fileType`, `name`, `stub`, and `options`; referenced child members are included.
- `children`: immediate child dot paths, in declaration order.
- `uses`: the referenced scaffold name, or `null`.

Recursive references stay finite: `section.section` has `uses => section`; it does not expand the referenced tree. Disabled nodes are omitted after resolution; `problems()` supplies the actionable diagnostics. `mod:cache` stores this finite metadata under `scaffolds` in its discovery payload. Providers continue registering executable recipe callbacks normally.

L6 owns S20's `mod:list` tree view and problem section. This lane has not edited ListCommand.

## Lane 7: docs handoff

Update `going-further/scaffolds.md` in this order:

1. Asking Questions — S13, S15, QuestionModesTest and TreeSafetyTest. `asks()` supports text, list, choice, confirm, model and class. Questions add options. A list uses comma-separated terminal text, and accepts `--tabs=A,B` or repeated `--tabs=A --tabs=B`. Confirms use `--flag` or `--no-flag`. Without a terminal, use the default or exit naming the answering option. A string default may contain `{name}`.
2. Parts: Scaffolds Inside Scaffolds — lead with S14's Inventory/Widget and Overview/Details/Notes, then the independent S15 leaf. Values go down through `with:` and questions; aliases come up as `tab.page` inside inserts and `tab.History.page` outside. Inline parts can contain template members and slot options. Each node's part inserts can edit that parent's own member aliases; a descendant cannot edit a grandparent alias.
3. Growing a Cluster Later — S17 and S18. `mod:resource-tabs.tab Inventory:Widget History` uses the same planner as `each()`. It resolves the parent's deterministic member names, without a cluster state file. The terminal lists every file and every insert before the one write confirmation. Missing anchors, duplicate outputs and repeated inline inserts refuse before writing. Missing clusters offer to create the root in a terminal; the noninteractive message names the root command. Creation with three tabs is byte-identical to creation with two followed by growth.
4. Registering Routes — S19. Insert stubs live at `stubs/mod.insert.<name>.stub`. Use an anchored target such as `@module/routes/web.php`. The module provider loads the routes file in 0.2; automatic route discovery remains later. Routes files are the only files mod offers to start or add a missing anchor to. These changes are staged until the final confirmation.
5. Overriding One Part — S20 and TreeSafetyTest's package provenance. `Mod::scaffold('resource-tabs.tab', fn (Part $p) => ...)` replaces just that node, during creation and growth. L6 supplies the list view.
6. Recursive Scaffolds — S21. `Install/Requirements` writes the short class name in `ViewModels/Guide/Install/RequirementsSectionViewModel.php` with matching namespaces. Recursion stays a reference until invoked; the limit is 10. Deeper dot paths accept slash-separated inputs for their ancestor parts, for example `mod:nested.group.item Inventory:Widget First/History`.

Update `reference/layout-api.md` with `asks()`, `each()`, `part()`, `Part::uses()`, `inserts()`, the registry method above, and insert stubs. `include()` copies questions, members, parts and repetitions; a subsequent `part()` replaces the inherited part with the same name. S22 pins include-cycle diagnostics and orphan dotted-name rejection.

Use valid PHP for configured parts: the catalogue's positional closure after named arguments must become `configure:`:

```php
->part('tab', uses: 'tab-page', with: ['base' => '{{ base.fqcn }}'],
    configure: fn (Part $p) => $p
        ->inserts(into: 'base', at: 'tabs', stub: 'tabs-entry'))
```

An inline positional closure also works: `->part('tab', fn (Part $p) => ...)`.

Document chained name forms left to right (`{{ name.plural.kebab }}`), literal braces (`{{{ model.camel }}}` gives `{widget}`), and `{{ tabs.array }}` as a PHP array literal. Stubs do not gain loops or conditionals. Inserts use fully qualified class names; they do not edit imports or parse PHP. Anchors match `mod:<anchor>` literally in `//`, `#`, Blade, and HTML comments. Keep anchors in all base variants (R65). Existing CRLF files retain CRLF, anchor indentation and trailing whitespace. Stub indentation and blank lines are preserved.

S16 covers the request-based section index (B) and public `tabs()`/`href` variant (C), alongside layout tabs (A). Each generated file is linted and all generated classes load in an isolated PHP process. Native generators retain their existing import grouping; exact S14 output and its base file are pinned in acceptance tests. The plan prints each insert individually, rather than aggregating three entries into one row.

Frontend/plain-file members remain later. No public docs pages were edited by this lane; their docs harness belongs to L7.

## Validation and delivery

The initial tests were committed red, followed by questions/repetitions, parts/inserts, and the dot-path registry/overrides/recursion. S1–S12 remain covered; S13–S22 are covered here except L6's S20 list rendering. Extra tests cover cancelling the whole subtree, routes-start staging, inline insert duplicates, ownership, deeper overrides, template slots, finite cache metadata and rollback after a generation-time anchor failure.

Local gates use Herd PHP 8.4 and Laravel 13: `composer test` (1,177 passing, one existing skip), `composer analyse` and `composer lint` passed. Hosted Ubuntu/Windows × PHP 8.3–8.5 × Laravel 12/13 CI has not been run from this unpushed lane. No push, PR, merge, deployment or Solo write was performed. Lead owns integration and hosted CI.
