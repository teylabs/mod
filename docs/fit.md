# Checking how a layout or recipe fits

The read-only Laravel Boost `mod-fit` tool compares a proposed layout or scaffold
recipe with the app hosting it. Neither proposal needs to be installed. Start
with `mod-survey` for actual structure, then iterate on the proposal without
registering it or moving files. Use `mod-plan` when a registered generator's
write plan is what you need.

## Input is data, not executable PHP

Supply `layout`, `recipe`, or both. Unknown keys and malformed definitions are
refused. The tool always scans `base_path()`; paths in the input describe expected
placement or variance evidence, never another scan root.

### A proposed layout

```json
{
  "layout": {
    "base": "modules",
    "path": "app/Areas/{area}",
    "types": [
      {"id": "controller", "in": "@area/Controllers", "suffix": "Controller"}
    ]
  }
}
```

- `base` is required: `laravel`, `modules`, `features`, `slices`, `type-first`
  or `ddd`. It starts from shipped defaults, not the app's customizations.
- `path` optionally supplies the group path, as in `Layout::path()`.
- `pageEntries` optionally supplies entry file stems, using the same inclusion
  policy as `Layout::frontend(pageEntries: ...)`. Omitted or null includes every
  page file, preserving 0.6 behaviour; `[]` includes none. Stems accept letters,
  numbers, underscores, hyphens and `*` wildcards, not paths or extensions.
- `mounts` optionally adds or replaces roots: objects with `id`, `namespace`
  (a PHP namespace or null for plain files), and project-relative `path`.
- `types` optionally adds or overrides file placement: objects with `id`, `in`,
  optional `suffix` and optional plain-file `extension` (such as `.tsx` or
  `.blade.php`). `in` accepts the layout's placeholders, anchors and root prefixes,
  such as `work:Tasks`. Other built-in metadata stays unchanged.

The normal layout compiler and reverse mapper decide ownership. The tool does
not run app layout, template, discovery or recipe loaders, register commands,
or change the active layout. This small input describes placement, not the full
PHP layout API; it does not accept callbacks, stubs or relations.

### A proposed scaffold recipe

Represent one intended invocation as concrete expected paths:

```json
{
  "recipe": {
    "name": "invitation",
    "members": [
      {"id": "model", "path": "app/Models/Invitation.php", "class": "App\\Models\\Invitation"},
      {"id": "migration", "path": "app/Modules/Team/Migrations/create_invitations_table.php"},
      {"id": "page", "path": "app/Modules/Team/pages/Invitations/Index.vue", "page": "Invitations/Index"},
      {"id": "accept", "path": "app/Http/Controllers/AcceptInvitationController.php"}
    ],
    "tables": ["invitations"]
  }
}
```

`name` and a non-empty `members` list are required. Each member has a unique `id`
and project-relative `path`; optional `class` and `page` provide identities for
matching and reference attribution. `tables` optionally lists table names for
outside migration touches. The name labels the report, not an installed recipe
to load. Expand groups, answers, parts and member name templates into these
paths before calling the tool; it does not interpret PHP scaffold definitions.
When both inputs are present, layout ownership and recipe member evidence appear
in the same report; recipe paths remain explicit, not derived from the layout.

### Folder members and page entries

A member with `pattern` describes a folder, not a generated file. Its `path` is
the proposed layout's frontend path key: `pages`, `components` or `views`.
Supply a layout with that key declared. This is plain data; fit does not load a
scaffold or depend on its folder-member classes.

```json
{
  "layout": {"base": "laravel", "pageEntries": ["Page", "Modal"]},
  "recipe": {
    "name": "widget-pages",
    "members": [
      {"id": "pageFolder", "path": "pages", "pattern": "Widget"},
      {"id": "edit", "path": "resources/js/pages/Widget/Edit/Page.vue", "page": "Widget/Edit/Page"}
    ]
  }
}
```

This folder accounts for every permitted file under `pages/Widget/`, including
`Layout.vue`, action pages and undeclared partials. File members inside it are
allowed; two folders claiming the same file conflict. An explicit file member
takes precedence in `owned`, while outside references can identify both members.
`pattern` accepts literal folder segments, whole-segment placeholders such as
`{name}` (any single segment), `*` (within one segment) and `**` (zero or more
folders). The selected folders include their whole subtrees. Use a concrete
pattern for one invocation, or placeholders to check recurring folders across
the proposed layout's groups. No directory is scanned outside the normal safe
file inventory. Folder members do not accept `class`, `page` or single-file
variance evidence; empty matches appear in `missing` with their path key,
pattern and `can_mark_variance: false`.

With the entry policy above, `Widget/Edit/Page` and `Widget/Edit/Modal` remain
render names. `Widget/Layout` and `Widget/Edit/Partials/Form` appear in
`non_entries`: each row has its file `path` and the `name` that would no longer
resolve. They can still be owned files. A literal `Inertia::render()` targeting
one produces a `render-non-entry` conflict with the caller `path`, `target` and
`page_path`; a recipe file claiming its `page` identity produces
`page-non-entry`. Fit checks literal render references even inside owned files.
Missing targets and computed references are not evidence of non-entry status.

### Agent evidence for variances

The tool cannot decide that accepting an invitation through registration is the
same capability as a dedicated accept controller. An agent can record that
decision in the next call:

```json
{
  "variances": [
    {"member": "page", "path": "resources/js/pages/Settings/Members.vue"},
    {"member": "accept", "path": "app/Http/Controllers/RegisterController.php", "mechanism": "registration-flow"}
  ]
}
```

Include the same recipe in that call. `member` must name a proposed member and
`path` must be an existing file in the permitted scan. Omit `mechanism` for a
renamed or relocated role; supply it for another mechanism. The report marks
these as `evidence: agent`; it does not present them as inferred facts.

## The report

All paths are project-relative. Keys and lists are sorted and duplicates removed;
repeating the same request against the same tree returns byte-identical JSON.

| Field | Meaning |
| --- | --- |
| `owned` | Existing files a proposed layout places or a recipe matches exactly; includes `file_type` and/or `member`. |
| `unowned` | Permitted app files neither input accounts for. A relocated member is not also unowned. |
| `conflicts` | Ambiguous placement or member candidates, class identity mismatches, overlapping claims, or non-entry page/render identities. |
| `non_entries` | Existing page files excluded by the proposed entry stems, with the render names they would lose. Empty with the default policy. |
| `variances` | Member id, expected path, actual `path`, reason (`different-path` or `different-mechanism`) and evidence (`structure` or `agent`). |
| `missing` | Members with no match; files include `can_mark_variance: true` for a later agent decision, folders include their pattern and false. |
| `outside` | Files outside matched recipe members that reference their actual classes/pages or touch a declared table; includes recipe attribution. |
| `configuration` | Unowned static callback registrations, with file, line, target, referenced members and recipe attribution when supported. |
| `vendor` | Referenced package-owned classes and their local vendor paths; not recipe-owned app files. |
| `skipped` | Symlinks and files exceeding 1 MiB, with reasons. |
| `unparsed` | PHP files that could not be parsed; not evidence that a capability is absent. |

Recipe matching tries the exact path, then the optional full class identity,
then the longest identical path tail. Migration timestamps are ignored for the
last step. A unique relocated match becomes a variance; tied candidates become
a conflict, never an arbitrary choice. This is structural evidence, not proof
of behaviour. Renamed roles require agent evidence. An exact-path class mismatch
is a conflict rather than an owned recipe member. A different mechanism may
explicitly share its evidence file with another member.

Outside references reuse the survey's PHP tokenizer for class imports, class
uses and literal Inertia page names. Table touches recognize literal names in
`Schema::create`, `table`, `drop`, `dropIfExists` and `rename`. Code configuration
recognizes static calls receiving anonymous functions or arrow functions, such
as package callback overrides in a service provider. Schema callbacks are table
touches, not configuration. Callback bodies and values are never returned.
Configuration is attributed to the recipe when its call references a matched
member or lives in a matched member file; otherwise `recipe` is null so an agent
can review it without an unsupported attribution. A layout-owned provider does
not make its configuration recipe-owned.

Computed names, instance calls, callbacks supplied through variables, dynamic
table names, frontend imports and behavioural equivalence are not inferred.
The fit check does not query runtime routes, policies, Git or the database.

## Scope and privacy

`mod-fit` reuses `mod-survey`'s enforced `Scope`: only `base_path()`, with `vendor/`
used solely to classify referenced classes from already loaded Composer maps.
It never invokes an autoloader to inspect those classes. A class whose Composer path
is under `vendor/` counts as vendor-owned by that path alone, so packages linked
from Composer path repositories work; the link is not followed and the file is
not read. There are no network
calls, writes, source execution, alternate roots or parent/sibling/home scans.

The scan skips all discovered symlinks, nested repositories, `node_modules/`,
`.env*`, `storage/`, keys, logs, databases, `bootstrap/cache`, `public/build`,
`public/hot` and `public/storage`. Files larger than 1 MiB (1,048,576 bytes) are
skipped before reading. Protected, vendor, absolute and traversal paths are
refused in proposals; skipped files cannot serve as agent variance evidence.
Only structural identities and locations are returned, never file contents.
Run the tool separately in each app to compare reports.
