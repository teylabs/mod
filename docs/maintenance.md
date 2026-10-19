# Keeping generated files up to date

> **Experimental.** This API is new in 0.8 and may change before 1.0. It supports POSIX checkouts (macOS, Linux) only. Windows roots are refused, although Mod's generators still work on Windows.

When a package or recipe updates the files it generated, `Tey\Mod\Maintenance` updates an app's copies without overwriting the developer's edits. It compares three versions of each file:

- **original**: what was installed last time
- **current**: what is in the checkout now
- **target**: what the new release generates

Only a file that still matches its original is replaced. Mod never merges or forces anything. An edited file is a conflict, and a conflict stops the whole update before anything is written.

Mod handles whole generated files only. Migrations, providers and other side effects stay with the host package.

## Artifacts

An `Artifact` is an immutable description of what a recipe generates: a name and version, exact relative paths and their bytes, the selected groups (for example vertical units such as `Billing`), host bindings, and the IDs of the checks that must pass after installing it.

```php
use Tey\Mod\Maintenance\Artifact;

$target = new Artifact(
    name: 'acme/blueprint',
    version: '2.0.0',
    files: ['app/Providers/AcmeServiceProvider.php' => $bytes],
    groups: ['Billing'],
    bindings: ['tenant' => 'workspace'],
    checks: ['boot', 'routes'],
    metadata: ['source' => 'acme/blueprint@2.0.0'],
);
```

An artifact's identity comes from its content, and the original bytes are kept, so an update can always be checked against exactly what was installed. Build artifacts from trusted recipe output, never from editable JSON.

`metadata` is optional provenance (source references, package constraints, applied migration IDs). It must be plain JSON data, no deeper than 16 levels and no larger than 1 MiB. It is recorded, never acted on: it can't add files, skip checks or run anything.

## Planning

`Checkout::inspect()` reads the checkout and returns a `FilePlan`:

```php
use Tey\Mod\Maintenance\Checkout;

$plan = Checkout::inspect($root, $appId, $installed, $target, $inputPaths, ['php' => 'passed']);
```

It reads only the files the two artifacts name, plus the host inputs in `$inputPaths` (for example `composer.lock`). It writes nothing. Each file is classified:

| State | Meaning |
| --- | --- |
| unchanged | Current matches original and target |
| replace | Current matches original, and the target changed |
| restore | An owned file is missing |
| create | A new target file, and nothing is there yet |
| conflict | Current differs from original, even if it equals the target |
| unowned | A new target path collides with a host file |
| claim | A listed new target path already has identical bytes (see [Claiming existing files](#claiming-existing-files)) |

A conflict, an unowned collision or a compatibility result other than `passed` makes the plan non-executable. Files the artifacts don't name are never touched and never become owned.

The inspector refuses symlinked roots and parents, case aliases, and directories where files are expected.

## Applying and resuming

```php
use Tey\Mod\Maintenance\Maintenance;

$report = Maintenance::diagnose($plan, $inputPaths); // read only

$result = Maintenance::apply($plan, $inputPaths, $intent, $checks, exclusiveCheckout: true);

if ($result['status'] !== 'completed') {
    exit(1);
}
```

- `$intent` names this update. Retrying the same intent is safe: a finished update returns the same result without writing, and a different plan under the same intent is refused.
- `$checks` maps every check ID in the target artifact to a closure that returns `true` on success. Checks run after the files are written. If one fails, the result is `verification_failed` and the installed version stays where it was.
- `$inputPaths` must include `verification-policy` and `verification-code` files that represent your check registry, so a changed check invalidates an earlier result.
- `exclusiveCheckout: true` confirms that nothing else edits the checkout during the update. Mod takes a lock against other Mod updates, but it can't stop an editor or another tool.

`Maintenance::resume()` finishes an interrupted update of the same intent, using the trusted original and target artifacts.

Mod records the new installed version last, after every check has passed. If a process dies part-way, rerunning `apply` or `resume` either finishes the update or reports exactly what is left.

Only `completed` means success. Edited, stale, foreign or unsafe state throws a refusal. Leftover temporary files from an interrupted run are reported as `orphaned` and left in place for you to inspect.

## Several lineages in one checkout

Each artifact name is a lineage: one generated feature, such as `acme/blueprint:billing`, installed and upgraded on its own. A checkout can hold many lineages, and Mod keeps one installed record for each. `Checkout::installed()` lists them, read only:

```php
Checkout::installed($root, $appId);
// ['acme/blueprint:billing' => ['identity' => 'sha256:…', 'version' => '2.0.0', 'files' => ['app/Billing.php' => '<sha256>']]]
```

Lineages never share files. When a target path is owned by another installed lineage, it's a `conflict`, and its plan entry has the reason `owned_by:<name>`. Ownership is never transferred.

While one lineage has an unfinished update (for example, its checks failed and it hasn't been resumed yet), Mod refuses to inspect, apply, resume or claim for any other lineage, with the reason `pending:<name>`. The unfinished lineage can still resume. Finish it with `Maintenance::resume()`, then continue with the others.

Two optional artifact parameters describe how lineages relate:

```php
$target = new Artifact(
    name: 'acme/blueprint:billing',
    version: '2.0.0',
    files: [...],
    groups: [],
    bindings: [],
    checks: ['boot'],
    requires: ['acme/blueprint:foundation' => '*'],
    hostOwned: ['config/branding.php', 'app/Domain'],
);
```

- `requires` names lineages that must already be installed, each at an exact version or `'*'` for any. Otherwise `Checkout::inspect()` refuses. Check version ranges yourself and pass the result in as an ordinary compatibility outcome. While a lineage requires another at an exact version, updating that lineage to a different version is refused with the reason `required_by:<name>`. `'*'` never blocks an update.
- `hostOwned` lists path prefixes this lineage leaves to the host. `app/Domain` covers that path and everything under it. No other lineage may own, write or claim there; such a path is a `conflict` with the reason `host_owned:<name>`. Several lineages may declare the same prefix. Mod compares these paths but never reads them, and refuses to install a lineage whose prefixes cover a file another lineage already owns.

A checkout written by 0.8 keeps working. Its `installed.json` is read as the record for its own lineage and moves to the new layout on that lineage's next update.

## Claiming existing files

An app may already contain files a lineage would generate, for example when it adopts a recipe it once copied by hand. Pass the paths you have reviewed as `claim`:

```php
$report = Maintenance::diagnose($plan, $inputPaths);
// $report['claimable']: unowned paths whose bytes already equal the target

$plan = Checkout::inspect($root, $appId, $installed, $target, $inputPaths, $compatibility, claim: ['app/Providers/AcmeServiceProvider.php']);
```

A claimed path whose bytes are identical to the target has the state `claim`. Mod doesn't write it, and after the update it's owned like any generated file. Differing bytes are a `conflict`, and existing paths you didn't list stay `unowned`. A claim never overwrites anything. To resume an interrupted update, pass the same `claim` list to `Maintenance::resume()`.

## Limits

- Experimental, and POSIX checkouts only.
- Whole-file replacement. No merging and no force option.
- One Mod writer per checkout. Other editors aren't excluded.
- Covers process interruption, not power loss.
- Mod never cleans up or adopts orphaned files.
- Removing an installed lineage isn't supported yet.
