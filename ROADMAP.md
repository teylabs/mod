# Roadmap

mod 1.0 lets you decide where every generated file goes (modules, features, slices, DDD or your own layout) while you keep Laravel's own `make:*` commands, discovery and conventions. Its public API, versioning policy and docs will be stable enough to build apps and packages on without churn.

**Versions are milestones, not dates.**

## Shipped

- [x] **[v0.1 — Layouts and generators](https://github.com/teylabs/mod/releases/tag/v0.1.0).** Six built-in layouts and a `mod:*` command for every file type, built on Laravel's own `make:*` commands.
- [x] **[v0.2 — Templates, scaffolds and discovery](https://github.com/teylabs/mod/releases/tag/v0.2.0).** Generator templates, multi-file scaffolds, `mod:list`, `mod:autoload`, and discovery of providers, commands and listeners.
- [x] **[v0.3 — Frontend in modules](https://github.com/teylabs/mod/milestone/1?closed=1).** Inertia pages, Blade views, frontend templates and module routes live beside a module's PHP, and `mod:install inertia` wires Vue and React apps.
- [x] **[v0.4 — Rename and move](https://github.com/teylabs/mod/milestone/2?closed=1).** `mod:rename` renames or moves a generated cluster and updates PHP and frontend references, plus a supported API for packages built on mod. v0.4.1 lets an explicit mount win over an inherited exclusion.
- [x] **[v0.5 — Proven in real apps](https://github.com/teylabs/mod/milestone/4?closed=1).** Existing Laravel-DDD apps moved to mod, and what they ran into was fixed: Inertia page checks and direct page loads for module pages, MCP classes in modules, and module translations.
- [x] **[v0.5.1 — Installer recognizes existing wiring](https://github.com/teylabs/mod/milestone/10?closed=1).** `mod:install inertia` says "Already wired." for wiring an app already has, instead of asking for it again.
- [x] **[v0.6 — Packages build on mod](https://github.com/teylabs/mod/milestone/5?closed=1).** Packages ship vendor-named scaffolds (`mod:acme/pages`) that mod reconciles with an app's own, and register route files, Inertia pages and frontend wiring; they can read the app's stack, including Svelte and Livewire. Apps gain a module's own database connection, frontend files in roots that aren't modules, and per-module assets.
- [x] **[v0.6.1 — Packages from path repositories](https://github.com/teylabs/mod/milestone/11?closed=1).** Packages installed through symlinked Composer path repositories work, unusable frontend declarations are reported instead of breaking boot, and TypeScript wiring maps bare aliases.
- [x] **[v0.7 — Learn an app's patterns](https://github.com/teylabs/mod/milestone/6?closed=1).** Agents can survey an app, describe its patterns, and propose them as a layout and recipes, reading only the app they're installed in. Scaffolds ask for lists of records, repeat and toggle sections with template blocks, and make optional members. Apps can define their own page shapes from page entries, folder scaffolds and nearest-folder layouts.
- [x] **[v0.7.1 — API route prefixes](https://github.com/teylabs/mod/milestone/12?closed=1).** A route group can serve its API under a prefix of its choice, such as `/v1`, instead of always `/api`.
- [x] **[v0.7.2 — Rerunning scaffolds](https://github.com/teylabs/mod/milestone/13?closed=1).** A dry run shows which existing scaffold files are unchanged or edited, scaffold trees honour `--force` and `--skip-existing`, and inserts can reference a root migration.
- [x] **[v0.8 — Keep generated files up to date safely](https://github.com/teylabs/mod/milestone/7?closed=1).** An experimental maintenance API updates generated files that are still untouched, refuses edited files and host-file collisions before any write, runs the host's checks and records the installed version last. Interrupted updates can be resumed. POSIX checkouts only.
- [x] **[v0.9 — Adopt generated files feature by feature](https://github.com/teylabs/mod/milestone/14?closed=1).** An app can hold several lineages, each with its own installed record. Lineages can't own each other's files, can require one another, can reserve host-owned paths, and can claim existing files whose bytes already match. A 0.8 checkout migrates on its next update.
- [x] **[v0.9.1 — Dot-prefixed paths](https://github.com/teylabs/mod/milestone/16?closed=1).** Lineages can own, claim and reserve files like `.env.example` and `.github/workflows/…`.
- [x] **[v0.10 — Read back lineages](https://github.com/teylabs/mod/milestone/15?closed=1).** Tooling can read an app's installed lineages and unfinished updates through Mod's public API.
- [x] **[v0.11 — Remove and move generated files](https://github.com/teylabs/mod/milestone/17?closed=1).** An update can delete or release files its target drops, never deleting an edited one, and a file can move between lineages by release then claim. Discovery no longer stops production boots without dev dependencies.
- [x] **[v0.11.1 — Retry finished moves](https://github.com/teylabs/mod/milestone/19?closed=1).** A finished release step can be retried after a later step rewrites the moved file.
- [x] **[v0.12 — Switch modules on and off](https://github.com/teylabs/mod/milestone/18?closed=1).** Turn a module off without deleting it, and recognize a finished update that a later one superseded.

## The countdown to 1.0

- [ ] **[1.0 — A stable promise](https://github.com/teylabs/mod/milestone/8).** Laravel-DDD apps moved to mod in field trials, with a guide for moving. A frozen public API, a versioning and support policy, complete docs with an upgrade guide, and a release candidate.
- [ ] **[1.x — After 1.0](https://github.com/teylabs/mod/milestone/9).** In no particular order.
