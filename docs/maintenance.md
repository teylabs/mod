# Whole-file maintenance planning

The initial API makes read-only decisions about explicitly generated files. It is not an upgrade command. The pure planner does not inspect the filesystem, install dependencies, run checks, repair files or advance a version. A separate inspector supplies filesystem observations. Applications continue to use public scaffolds to render deterministic recipe output.

Create an `Artifact` from trusted recipe output: a name, version, exact relative paths and bytes, selected modules, host bindings and required verification identities. Its content-derived identity includes recoverable original bytes, not just hashes. A stored hash without a matching immutable resource is insufficient to reconstruct an artifact. Hosts must resolve and verify the artifact against an independently pinned identity before using it. Never construct trusted artifacts from an editable plan alone.

`FilePlan::between(appId, root, baseline, target, current, inputs, compatibility)` compares the original installed artifact, observed current bytes and immutable target artifact. The inspector must supply a canonical absolute POSIX checkout root and observations for only the union of baseline and target paths. Missing keys mean absent files. Inputs identify the dependency lock, host configuration and policy. Compatibility outcomes are named `passed`, `failed` or `unavailable`; an empty or nonpassing set prevents an executable decision. Compatibility checks and verification checks are different: this initial API executes neither.

Each file is classified:

| State | Observation |
| --- | --- |
| unchanged | Current equals original and target |
| replace | Current equals original; target changed |
| restore | Explicitly owned original path is missing |
| create | New target path is absent |
| conflict | Current differs from original, even if it happens to equal target |
| unowned | New target collides with a host file |

Any conflict or unowned collision makes the whole plan nonexecutable. An upstream template change therefore does not misclassify an untouched original as edited. Existing scaffold dry-run `edited` compares current recipe output; it is not an original-baseline comparison.

The plan identity binds application ID/root, original and target artifacts, every observed file fingerprint, host-input identities and compatibility outcomes. It is an equality fingerprint, not a signature or permission grant. A caller must not trust editable JSON as authority to write, execute commands or widen ownership. No arbitrary commands, executable payloads or serialised callbacks are accepted. Module/binding changes, lineage changes and removal of generated ownership are currently refused pending explicit policy. Unknown host files do not become owned merely because they share a directory.

Path validation rejects traversal, absolute paths, separators outside portable relative paths, case collisions and file/parent overlap. This pure planner cannot detect symlinks or observe existing directory aliases. No platform-specific filesystem or concurrency guarantee is claimed. A later filesystem executor must reject linked parents and journal paths, re-inspect immediately before effects, preserve original bytes durably and require an exclusively owned checkout. Advisory locks coordinate participating writers; they cannot exclude arbitrary editors. There is no force mode or semantic merge.

Successful planning is not successful verification or a completed upgrade. Migration, provider and domain effects are outside this API. No public release or installed compatibility is implied by an unreleased local API.

The first maintenance API supports POSIX checkout roots only. Drive and UNC roots refuse explicitly, including when Mod itself supports generators on Windows. The lexical guard refuses empty/dot segments, duplicate separators, backslashes and control characters. It cannot prove that a supplied root equals filesystem `realpath`; a future inspector must establish that identity and reject symlink aliases. Windows maintenance remains unsupported until a filesystem implementation and platform-specific evidence establish it.

## Read-only checkout inspection

`Checkout::inspect(root, appId, baseline, target, inputPaths, compatibility)` establishes the supplied root against actual `realpath` and requires an existing POSIX directory with no linked ancestor. It reads only the explicit original/target file union plus named host input paths. Input fingerprints bind both path and content, including absence. Unknown host files remain outside ownership. It creates no directories, metadata, journals or version records.

The inspector refuses symlinks at owned/input paths or any parent, case aliases/collisions and directories where files are expected. Existing `.mod-maintenance` metadata is checked recursively for linked parents, journal files, unsupported entries and case collisions, but no metadata content is trusted or loaded yet. It cannot promise a coherent snapshot while unrelated writers edit the checkout; an executor must hold the declared exclusive-checkout boundary, re-inspect and check every planned fingerprint immediately before writes. No repair or lock implementation exists in this increment.

## Internal preparation increment

The unreleased internal journal is not callable by consuming packages. There is still no public repair/install/ownership-recording API. It accepts a trusted executable plan only under an explicit exclusively-owned-checkout precondition, re-inspects before metadata creation and after a nonblocking advisory claim, then prepares original/target bytes and current fingerprints. Freshness includes host inputs. Original and target bytes are recoverable base64 data; check identities contain no executable commands. No generated file or installed version changes.

Preparation creates private metadata with an explicit Mod owner/app/root marker. It never adopts unmarked, malformed or foreign metadata. Journal paths are derived from a bounded intent hash, not supplied paths. Existing data must exactly match the expected trusted plan envelope; changed intents, extra/altered payload and unknown states refuse. Repeated identical preparation returns the existing receipt. Atomic same-directory writes flush and read back complete data, while symlink/case/type and hardlinked metadata paths refuse. This is process-crash preparation, not a filesystem/power-loss durability guarantee. Advisory locks exclude participating maintenance handles, not arbitrary editors.

An interruption before ownership bootstrap is renamed can leave a uniquely named private staging directory. It is not adopted or recursively deleted as part of a later operation; noninteractive staging reconciliation remains pending. Unsupported/partial preparation does not become an installed-version receipt. No public installation baseline has been established by preparation alone, and supplied original artifacts still require independent trusted source resolution. File replacement, partial-write reconciliation, required check execution, version-last commit and two-app recovery remain later increments.

## Internal whole-file replacement increment

There is still no public consumer repair API. The internal file phase requires a live prepared advisory claim. It preflights all explicit file and host-input fingerprints, preserves durable original bytes, then replaces only expected original/absence bytes with exact target bytes. Each replacement uses a same-directory temporary file, complete write/flush, immediate fingerprint/path recheck, atomic rename and readback receipt. Existing executable permissions are retained; file identity/inode preservation is promised only for already-target files that are left alone, not replaced files. No directory-wide ownership, force option or semantic merge is introduced.

`Journal::reopen` reconstructs an original plan from trusted baseline/target artifacts and validated saved original fingerprints, not arbitrary content supplied by a journal. Every envelope field must match that plan. Each current file must be its expected original/absence or its intended target; any third value or changed host input refuses before more writes. Already-target files are reconciled without rewriting them. Prepared receipts do not imply checks passed or a version committed. Closed claims refuse effects.

The file-phase journal uses internal format2 for explicit per-file receipts. Earlier internal preparation-only format1 is not automatically adopted; its selected intent refuses without overwrite. Neither format has been published or used by a public consumer. The separate owner marker remains format1. Unknown/altered/foreign journal payloads cannot execute commands or widen the trusted file list.

Focused POSIX tests include a separate writer process that self-terminates with SIGKILL after its first replacement and before the receipt. A fresh claim then reconciles that file and finishes the remaining file. This proves that bounded process-interruption case, not power-loss durability, arbitrary-editor exclusion, dependency/database recovery or a complete app upgrade. Ownership installation, installed-version commit and interrupted finalisation remain unimplemented. Staging-directory orphan reconciliation is still pending.

## Internal trusted verification

`Verification` is internal, with no public consumer entrypoint. It runs only explicitly registered host closures keyed by the target artifact's required check IDs. No shell strings, JSON callbacks or undeclared checks are accepted. Each check returns exactly true to pass; false, missing, nonboolean and throwing checks report failed, missing, invalid and error outcomes respectively. Error payloads are not included in the result. Checks are trusted in-process code and are responsible for their own resource/time boundaries; this is not a sandbox or a general job runner.

A live journal claim, unchanged named host inputs and exact target bytes for every generated file are required before and after every callback. A verifier that changes a file (even back to original A) or a dependency input invalidates verification before later callbacks. Arbitrary nonparticipating editors remain outside the exclusive-checkout contract. Outcomes are ephemeral and plan-bound, never persisted as reusable success across cold reopen or claim release. Journal state remains prepared; no installed ownership/version manifest is created. A later finaliser must run verification and recheck under the same live claim before recording a version last.

## Internal file installation and version-last recording

These internal types are not available to consuming packages. `Installation::prepare` uses an empty generated baseline and requires absent destinations. Existing files are unowned collisions even when they exactly match the proposed output. It never adopts a directory or guesses ownership from matching bytes. `Installation::upgrade` requires the recorded artifact to match the trusted immutable original artifact before returning a claim for file repair. A direct internal Journal remains a lower-level preparation primitive, not an ownership API.

`Finalization::record` obtains the installed-record fingerprint before reading and checks it again after reading. It recognises a complete, exact target/app/root/intent/plan/input/verification receipt before applying the ordinary original-artifact prerequisite. Otherwise it validates the prior installed record against trusted original bytes, selections and check identities, runs Verification itself, rechecks target and host inputs, then atomically replaces `installed.json` with an expected-old fingerprint. This record is the commit point and the last lifecycle write. A prepared journal alone never declares completion. Failed checks leave the old version (or no initial record), even if file repair has already completed; inspection/recovery must report that pending state truthfully.

Version recording requires existing named `verification-policy` and `verification-code` input files. The trusted host must map the actual registered policy, verifier implementation and relevant transitive configuration/source into these and other named inputs. A nominal file with unrelated code does not establish that contract. Runtime callback code comes only from trusted host registration, never JSON; this is not a hostile-local-administrator security boundary or a signature. Local receipts cannot widen the immutable registered artifact's file list.

The installed file-artifact record contains recoverable bytes/content identity, version, explicit paths, modules/bindings, required checks and the exact commit's app/root/intent/plan/input/compatibility/outcome identities. Unsupported, foreign or altered records refuse. This is not yet the complete opinionated blueprint manifest: package constraints, migration identities and conformance exceptions need the host's reviewed recipe/distribution contract. No database, provider, app-key or onboarding effects run here.

Cold reopening before the commit reruns checks against current files/inputs. An exact committed receipt returns a stable receipt/no-write result without rerunning effects; matching a version string alone is insufficient. Tests include separate POSIX children killed immediately before and after the installed-record write, then fresh-process-claim reconciliation. This covers process interruption, not power loss or directory-fsync guarantees. Installation bootstrap staging and temporary-file orphans, public API composition and real two-consumer upgrades remain pending.

## Experimental public composition

The current local experimental surface adds `Maintenance::diagnose`, `apply` and `resume` to the public Artifact/FilePlan/Checkout types. It has not been released. Consumers must use these public entrypoints rather than any internal types described above. The public API returns data only and closes its advisory claim on every completion, refusal or exception. POSIX and exclusively owned checkout restrictions remain; no arbitrary-editor CAS, Windows maintenance, power-loss, database/provider/migration or production update guarantee is added.

Create a trusted immutable original/target Artifact and a read-only Checkout plan. For initial installation, the original artifact has no generated files, with the same lineage/modules/bindings/checks as the target. Every destination must be absent, including when an unowned existing file happens to match the target. For an upgrade, the recorded installed original must match the exact trusted original artifact before any unfinished generated effects. A caller-created pure FilePlan is freshly inspected; it is never sufficient write authority by itself.

```php
use Tey\Mod\Maintenance\Maintenance;

$result = Maintenance::apply($plan, $inputPaths, $intent, $trustedChecks, exclusiveCheckout: true);
if ($result['status'] !== 'completed') {
    // Preserve diagnostics and return an unsuccessful CLI exit.
    exit(1);
}
```

`inputPaths` must include existing `verification-policy` and `verification-code` files plus the relevant dependency/configuration/source inputs. The trusted host maps these to the actual check registry and transitive implementation/policy; arbitrary files with nominal names do not satisfy that contract. Required check IDs are immutable artifact data; all required closures must be present and valid before unfinished generated effects. Undeclared IDs and nonclosure values refuse without callback execution. Only an exact already-committed retry may omit callbacks: supplied callbacks must still have valid types/IDs, and none run on that no-op path.

`apply` on an existing intent validates the original caller plan against the saved immutable envelope; it cannot reinterpret newly observed target bytes as original ownership. `resume` reopens only an existing exact intent from trusted original/target resources and current input/compatibility identities. Both recognise the exact committed target before any file receipt/write. Otherwise they preserve original/target-only recovery, run fresh required checks and write the installed file-artifact version last. Failed verification is returned as `verification_failed`; edited/stale/foreign/unsafe inputs throw refusals. `completed` alone is successful; an opinionated CLI must not hide any noncompletion behind a zero aggregate exit.

### Orphans are unresolved, preserved state

The public bounded inventory scans root staging names, direct maintenance metadata and the exact temporary-file prefixes of explicitly declared generated paths. It reads no candidate payloads, follows no linked candidates, recursively traverses no unknown directory, and emits only relative path/kind diagnostics. It limits directory and entry counts and refuses over-bound inventories. Unknown/unmarked/partial/linked/hardlinked candidates remain untouched. Names or matching hashes never authorise cleanup or adoption. Host files outside these scopes are not inventoried or owned.

`diagnose` performs no writes. Unresolved candidates return `orphaned` before a new generated effect or an already-completed no-op; verifier-created candidates also prevent completion. A prior installed version may remain recorded, but orphan presence is not reported as recovered success. This is conservative refusal, not automatic cleanup or successful noninteractive orphan recovery. A separately reviewed resolution protocol is still required for complete distribution/recovery acceptance; there is no force bypass.

Public-entrypoint tests cover two independently owned roots, absence/ownership/conflicts/staleness, policy/code/registry validation, no-op inode/receipt preservation, failure/retry, malformed/foreign state, root/link/hardlink boundaries and no-follow orphan preservation. Separate previously reviewed internal process-kill tests retain their bounded coverage; public composition is not yet the mandatory two real application consumers or published package compatibility.

## Bounded inert artifact provenance

Artifact accepts optional named `metadata` for provenance such as immutable source references, declared package constraints, applied migration IDs and reviewed exception descriptions. It is plain JSON data only: no objects, resources, callbacks, nonfinite numbers, invalid UTF-8 or non-list numeric maps. Validation precedes JSON encoding, so object serialisers are never invoked. Bounds are 16 nested levels, 4,096 structural nodes and one MiB of encoded JSON. Cyclic structures refuse through the same depth bound.

Empty/default metadata preserves the exact pre-extension artifact identity and installed artifact record shape. Nonempty metadata participates in identity and therefore saved envelope comparison, original installed ownership and completed no-op matching; it is persisted in the authoritative version-last installed artifact record. Changing a source reference under the same intent refuses. It does not create a competing sidecar version authority.

Metadata is inert evidence, not a waiver or execution plan. Only Artifact.files defines owned outputs, Artifact.checks defines required checks and the trusted host registry defines callbacks. A metadata field named files/checks/exceptions cannot add a path, skip a check or execute a migration. Hosts must verify the truth of declared constraints, source refs, applied steps and exceptions separately; recording them does not certify compatibility, onboarding, database history, browser behavior or provider health.

PHP readonly properties do not detach referenced array elements. Artifacts therefore reject PHP reference aliases in files, modules, bindings and checks, recursively throughout provenance. Flat plan observations/inputs/compatibility and inspection path maps use the same rejection. Ordinary copy-on-write arrays, including a caller alias to the whole argument variable, remain supported; later caller edits cannot replace validated values. Explicit scalar/array element aliases refuse before any identity serialization or metadata encoding. This prevents post-construction injection of executable objects, excessive values or cycles; readonly alone is not the guarantee.
