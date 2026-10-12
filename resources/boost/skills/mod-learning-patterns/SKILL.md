---
name: mod-learning-patterns
description: "Studies this Laravel app's recurring file placement, naming and related sets, then proposes a tey/mod layout and scaffold recipes for human review. Use when asked to learn an existing app's patterns or turn its conventions into generators. Not for architecture redesigns or comparisons with other apps."
license: MIT
metadata:
  author: Jasper Tey / Tey Labs
---

# Learn this app's patterns

Survey this app, read the code behind recurring structure, propose a layout and recipes, check their fit, and iterate. The output is proposed code for a human to review, never an automatically applied layout, recipe or migration of existing files. Load `mod-development` for the installed version's layout, scaffold and generator API.

## 1. Establish the app and ref, then survey

- Stay inside the app where this skill is installed. Use only app-relative source paths and safe local Git metadata. Do not scan parent or sibling folders, other repositories, nested repositories, symlinks, secrets or runtime data. Do not open `.env*`, keys, logs, databases, `storage/`, build output or caches. Vendor references identify dependencies, not app-owned recipe members.
- Read the app's instructions, `composer.json`, mod configuration and provider registrations. Compare available local branches by recent activity before surveying; the default branch may not be the development line. Use safe local Git metadata only, without fetching, running helpers or following metadata outside the app. If history is shallow, missing or unsafe, record that limit rather than expanding access.
- Choose the ref deliberately and state the branch and commit, why it represents current development, and whether the tree is dirty. A dirty survey describes the working tree, not just that commit. Do not switch branches, discard edits or create another checkout automatically; if the intended ref is not checked out, leave the survey pending until the person selects it.
- Call `mod-survey` with no arguments. Record its Git identity alongside the selected ref and note any disagreement. Read `types`, actual folders/namespaces, `unplaced`, `unresolved`, `unparsed`, `skipped`, `references`, `sets`, `recurring_sets` and `naming_patterns`. Call `mod-inventory` or run `php artisan mod:list --json` to separate configured placement from observed structure.

## 2. Confirm recurring structure in the code

- Start with recurring sets and naming patterns, not keywords. Compare at least two occurrences where available. Record relative paths, file types, namespaces, name transformations and the references that connect each proposed member. Shared base classes and route files are not new per-feature members just because they connect a set.
- Separate structure from meaning: tools show where files are and which references connect them; only reading representative source explains what they do. Read a representative file of each proposed member type and compare its counterpart in another occurrence. A `Member` may be a domain persona, a `Subscription` billing, and a `Group` a customer organization. Do not infer a recipe's purpose from those words.
- Check that the feature is alive before using its set as a recipe. Trace the controller to registered routes and the called methods, policy/gate uses to their definitions and registrations, and configuration reads to current keys. Check frontend page identities and callers when proposing pages. Use existing relevant tests where safe; source presence and survey edges alone do not prove runtime behaviour. Mark broken or unverifiable examples and keep them out of the reusable core.
- Use rename-aware local history when available: additions/deletions with `git log --full-history --find-renames --diff-filter=AD -- <safe-source-paths>`, and moves with `git log --full-history --find-renames --diff-filter=R -- <safe-source-paths>`. Follow a representative file with `git log --follow --find-renames -- <file>` when needed. Restrict paths to this app's non-sensitive source; inspect changed paths before opening historical content. Distinguish an adopted pattern from one later moved, dropped or abandoned. Report uncertainty when history is incomplete.
- Keep an evidence table: candidate, supporting occurrences, source meaning, route/gate/config checks, history, exceptions and confidence. Treat folder-only sets, naming matches, inferred policies and dynamic references as candidates, not proof.

## 3. Draft the smallest layout and recipes

- Describe the app as it is. Reuse the closest built-in layout when it already matches; otherwise propose `Mod::layout()` with `->extends()` first and only the necessary `->mounts()` / `->generates()` changes. Preserve Composer namespaces, casing, nesting, shared roots and existing file locations. Do not invent modules or move files to fit a preferred architecture.
- Propose the provider registration and `config/mod.php` selection as code, not active edits. Include every required import and any Composer mapping changes for review. Do not overwrite an existing layout or recipe.
- Define at least one supported set with `Mod::scaffold()` and `Scaffold`. Use explicit `->makes()` members, aliases and the app's observed names, such as `Store{name}Request`. Keep generator companions explicit so the recipe's membership is clear. Use sibling identities such as `{{ model.fqcn }}` in templates; verify option interpolation in the installed version rather than assuming it works in every recipe.
- Separate invariant structure from feature-specific behaviour. Reuse Laravel generators for ordinary class shapes; propose app templates only when representative bodies establish a reusable shape. Inspect generalized imports, authorization, validation, routes and page identities. Do not copy business rules, credentials or an example's model-specific assumptions into a template.
- State dependencies already installed, required answers, optional members, shared files and exceptions. A structural recipe need not reproduce every file in a survey set. Explain omitted or manual work rather than claiming the scaffold implements a working endpoint.

## 4. Check fit and generator output

- Check the proposal against the code with `mod-fit`. Discover its installed tool contract; do not guess arguments, response fields or a PHP API. Compare its findings with the evidence table, including both ordinary sets and exceptions. Fit checks structure, not business meaning or runtime correctness.
- If `mod-fit` is unavailable, state that fit remains unverified. Do not implement a substitute or claim a generator preview is a fit result. Continue preparing reviewable code and record the pending check.
- Keep the live app unchanged. If a disposable test fixture inside the app is authorized, register the candidate there and run `mod:list --json` to verify effective layout, paths, namespaces and recipe membership. Preview the recipe with `--dry-run --json --no-interaction`; inspect all files, inserts, warnings and `would_write`. Generate a fresh, asymmetric example name only in that disposable fixture, inspect its namespaces/imports, and run relevant tests. Never apply the candidate or generate sample files in the live app without approval.
- The package's `docs/learning-patterns.md` works through `tests/Feature/Boost/SurveyFixture.php`. Its acceptance test checks the exact proposed PHP with the inventory, preview and generated scaffold, without depending on the fit tool.

## 5. Iterate and hand off

Revise rules or recipe membership when code, history, fit findings or generated output contradict the proposal. Re-run the relevant checks after each revision; re-survey if the source tree or ref changes. Stop when representative occurrences and known exceptions are explained, generator output matches the proposed structure, and remaining uncertainty is explicit. Do not optimize for a perfect fit by hiding exceptions or absorbing unrelated files.

Return the chosen ref and dirty state, the evidence table, proposed PHP/config/templates, a sample command and expected paths, fit findings or the pending check, generator validation performed, exclusions and manual work. Keep observations, interpretations and proposals distinct. Ask for human review before adoption; do not activate the layout, register recipes, move existing files, run migrations or commit the proposal automatically.
