# Frontend rename helper

Internal protocol version 1. Invoke the helper shipped by Composer using an argument-array process, with the application's directory as cwd:

```text
node vendor/tey/mod/resources/js/rename/helper.cjs
```

Send exactly one JSON document on stdin. Stdout contains exactly one JSON response; diagnostics go to stderr. The helper returns candidates and never writes source, builds the application, installs dependencies or accesses the network. The PHP contributor validates the entire response before accepting candidates. Unsupported files retain every original byte and produce a located checklist with the destination and intended target where known.

Input fields:

- `version`: `1`.
- `base`: absolute application directory.
- `files`: original `{path, source, kind}` values for JS, MJS, CJS, TS, JSX, TSX, Vue and CSS; UTF-8 source, paths relative to the app. Invalid UTF-8 falls back in PHP before invocation.
- `inventory`: original project-relative file paths, including non-frontend inputs.
- `paths`: original-to-final member paths from the immutable cluster snapshot.
- `aliases`: alias-to-project-relative roots from compiled placement (`@modules` under the module root, `@` under `resources/js`). These aliases describe the canonical Mod wiring; arbitrary Vite executable configuration and custom aliases are not evaluated.
- `identities`: exact old-to-new resolved page/view names; unsupported string contexts stay advisory.
- `components`: exact old-to-new resolved frontend component names. Static Vue `<component is="Name">` literals use template AST coordinates; computed bindings stay advisory.

Response fields are exactly `version`, `edits`, `checklist`, `failures`, `dependencies`. `edits` are `{file, offset, before, after, category, line}`; `offset` is a UTF-8 byte coordinate in original source, and `line` starts at 1. Categories are `frontend-import` and `frontend-identity`. `checklist` rows are `{file, line, category, message, suggestion}`, with nullable suggestions. `failures` maps original paths to bounded diagnostic strings. A failed file returns no edits. `dependencies` maps absolute parser/module/manifest/lockfile paths to SHA-256 hashes. PHP additionally captures the helper and executable hashes and refuses overlapping edits, mismatched bytes/lines, unknown files, unsafe string content, unsupported versions, extra or malformed stdout and changed dependencies. The immutable plan snapshot rechecks dependencies before execution.

## Supported app dependencies

| Input | Installed app dependency | Supported series | Evidence |
| --- | --- | --- | --- |
| JS/MJS/CJS | `@babel/parser` | 7.29.x | Local real parser 7.29.9 |
| TS | `@babel/parser` with TypeScript plugin | 7.29.x | Local real parser 7.29.9 |
| React JSX/TSX | `@babel/parser` with JSX/TypeScript plugins | 7.29.x | Local real parser 7.29.9 |
| Vue script/script setup/template | `@vue/compiler-sfc` plus `@babel/parser` for script blocks | 3.5.x / 7.29.x | Local real parsers 3.5.43 / 7.29.9 |
| CSS URLs | No rewrite parser | Checklist only | Located URL diagnostics |
| Blade directives/components | PHP Blade literal lexer | Node independent | PHP fixtures with LF/CRLF |

These conservative series gates describe accepted capabilities; the exact versions above are the versions tested locally. Other versions, Vue preprocessors/custom blocks/external scripts, missing parsers and syntax failures fall back per file. Parsers must resolve under the target app's `node_modules`, including their metadata. No global npm fallback or automatic installation is allowed. Vite alone is not a promise that Babel and Vue parsing capabilities are present. Escaped import literals, ambiguous resolution, custom aliases, alias paths escaping their roots and computed imports remain checklist items. Relative and canonical alias imports resolve against the input inventory, with extension/index inference only when unique. An importing file's move recomputes all resolved neighbouring relative imports.

Blade literal include/extends/component/each directives and anonymous-component tags use exact resolved member identities. Comments, escaped directives, verbatim blocks, raw scripts/styles, PHP tags and Blade PHP/echo expressions are not rewritten by this contributor. PHP Inertia/render/view references belong to the PHP contributor. Imported React/Vue local binding names remain valid when only their import paths change; arbitrary global framework registration names are not inferred.

Real parser and fixture compilation checks, separately from the PHP matrix:

```sh
MOD_RENAME_TEST_APP=/absolute/path/to/app node --test tests/Node/rename.test.cjs
```

The local compilation check additionally uses that app's `typescript` compiler, Vue script compiler and template compiler. It proves fixture compilation, not a full application build or runtime. The PHP matrix uses the controlled `Runner` seam and forced unavailable-toolchain fixtures without requiring Node. The dedicated application harness supplies full build/type-check/runtime evidence separately.
