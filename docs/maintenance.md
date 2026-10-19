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
