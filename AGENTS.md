# Working on mod

mod (`tey/mod`) is a Laravel package (PHP 8.3+, Laravel 12 and 13) that lets an app choose where generated files go. Read `README.md`, `docs/` and the issue you're working on before changing code.

## Gates

Run these before opening or updating a pull request:

- `composer test`: the full Pest suite, about 2,000 tests (`-- --parallel` speeds it up).
- `composer analyse`: PHPStan, for both `src` and `tests`.
- `composer lint`: Pint (`composer format` fixes style).

Some changes also need:

- frontend build fixtures, run with `MOD_FRONTEND_BUILD=1` (Node);
- the published-API snapshot (`tests/Fixtures/published-api.json`) and the audits in `tests/Unit/Audit/`. Update snapshots deliberately and say so in the PR; never weaken an audit to pass.

CI runs the Linux and Windows matrix (PHP 8.3 to 8.5, Laravel 12 and 13), PHPStan, Pint, the Boost resources check and frontend builds. Every check must be green on the PR's final commit.

## Conventions

- Follow the surrounding code: naming, structure, comment density. mod is Laravel's ally: it reuses Laravel's own generators and conventions, and ships no opinionated scaffolds.
- Public API is marked `@api`; everything else is `@internal`.
- Every change gets tests that fail without it.
- Add an entry under `## Unreleased` in `CHANGELOG.md` (`Added`, `Changed` or `Fixed`), ending with the issue number, e.g. `(#119)`. Create the heading if it's missing.
- Update `docs/` and, when agent guidance changes, `resources/boost/`.
- Prose uses Canadian spelling ("behaviour", "colour"; "-ize" is fine): plain, short sentences, no marketing.

## Git and pull requests

- Work on a branch from the latest `origin/main`; never commit to `main`.
- Commit as `Jasper Tey <jasper@teylabs.com>`, with imperative, sentence-case subjects (e.g. "Let scaffold recipes declare a default name").
- **No attribution of any kind:** no `Co-authored-by`, no agent thread IDs, no "Generated with" lines, no links to agent sessions, in commits, PR titles or PR bodies.
- Open pull requests as **drafts**. Title them like a commit subject; the body says what changed, how it was verified, and `Closes #N`.
- Never merge, push to `main`, tag or release. Maintainers do that.
- This is a public repository: never include secrets, local absolute paths, private app names or internal notes.
