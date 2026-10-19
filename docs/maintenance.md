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

## Limits

- Experimental, and POSIX checkouts only.
- Whole-file replacement. No merging and no force option.
- One Mod writer per checkout. Other editors aren't excluded.
- Covers process interruption, not power loss.
- Mod never cleans up or adopts orphaned files.
