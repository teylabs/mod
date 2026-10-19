# Whole-file maintenance planning

The initial API makes read-only decisions about explicitly generated files. It is not an upgrade command. It does not inspect the filesystem, install dependencies, run checks, repair files or advance a version. Applications continue to use public scaffolds to render deterministic recipe output.

Create an `Artifact` from trusted recipe output: a name, version, exact relative paths and bytes, selected modules, host bindings and required verification identities. Its content-derived identity includes recoverable original bytes, not just hashes. A stored hash without a matching immutable resource is insufficient to reconstruct an artifact. Hosts must resolve and verify the artifact against an independently pinned identity before using it. Never construct trusted artifacts from an editable plan alone.

`FilePlan::between(appId, root, baseline, target, current, inputs, compatibility)` compares the original installed artifact, observed current bytes and immutable target artifact. The inspector must supply a canonical absolute checkout root and observations for only the union of baseline and target paths. Missing keys mean absent files. Inputs identify the dependency lock, host configuration and policy. Compatibility outcomes are named `passed`, `failed` or `unavailable`; an empty or nonpassing set prevents an executable decision. Compatibility checks and verification checks are different: this initial API executes neither.

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
