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

## The countdown to 1.0

- [ ] **[v0.6 — Modules own their frontend, translations and assets](https://github.com/teylabs/mod/milestone/5).** Frontend files in roots that aren't modules, per-module assets, and what packages built on mod need: their own pages, routes and command names, and a module's own database connection. The Laravel-DDD field trials and guide finish here.
- [ ] **[v0.7 — Learn an app's patterns](https://github.com/teylabs/mod/milestone/6).** Agents can survey an app, describe its patterns, and propose them as a layout and recipes. They read only the app they're installed in.
- [ ] **[v0.8 — Switch modules on and off](https://github.com/teylabs/mod/milestone/7).** Turn a module off without deleting it.
- [ ] **[1.0 — A stable promise](https://github.com/teylabs/mod/milestone/8).** A frozen public API, a versioning and support policy, complete docs with an upgrade guide, and a release candidate.
- [ ] **[1.x — After 1.0](https://github.com/teylabs/mod/milestone/9).** In no particular order.
