# Moving an existing app to a new layout

A layout declares where new files go. Changing it does not move existing code or rewrite persisted class names. Before changing namespaces, record every model's old and new fully qualified class name, its table and connection, and its effective morph identity. Keep this mapping with the reviewed deployment plan. Do this while the old application still loads its models; the new inventory alone cannot reconstruct the old names.

## Review polymorphic identities before deployment

Eloquent normally stores a model's full class name in polymorphic type columns. An explicit morph map can give it a stable alias. Inspect the application's `Relation::morphMap()` and each affected model's actual `getMorphClass()` value before the move; a model can override that method. If the value is the old class name and the namespace changes, rows retaining that value will point at a class that no longer exists. Freshly created test rows do not exercise this failure.

For each moved model, record this review table with real application values:

| Old class | New class | Morph value before | Morph value after | Connection and affected columns | Existing row count |
| --- | --- | --- | --- | --- | --- |
| `Domain\Inventory\Models\Document` | `App\Modules\Inventory\Models\Document` | old full class name | new full class name | application-owned inventory | measured before cutover |

An unchanged alias is stable only when the new application maps it to the intended new class. Introducing an alias now does not rewrite rows that already store a full class name. Inspect the actual distinct persisted values too: old rows may contain aliases or class names from earlier deployments.

Inventory all relevant connections and package-owned tables. Examples include media `model_type`, permissions' model type columns and OAuth `owner_type`. Use the app's migrations, schema and package configuration to find the columns, then perform read-only counts of the exact old values. A `*_type` suffix is a lead for review, not proof of a polymorphic class column; morph columns can also have other names. Do not run a blanket namespace-prefix replacement across every string column.

If rows need updating, write an application-owned, reviewed data migration with an explicit old-to-new value mapping, connection and table/column allowlist. Update exact matching values only. Mod does not write or execute data migrations unasked. Preserve a backup and verify the migration with representative old rows and the application's real relation reads.

Plan rollback before the update. Swapping the mapping in `down()` can rewrite unrelated rows that already held a destination value, including rows created after deployment. Record and retain the provenance needed to restore only rows changed by the cutover, or state that database rollback requires restoring the reviewed backup. Do not call a prefix rewrite reversible without this evidence.

## Drain work that serializes classes

Queued jobs, serialized model identifiers, notifications, sessions and caches can name old classes. Inventory these separately from morph rows. Stop producers and workers as appropriate and drain old queued jobs under the old code before deployment; delayed or retried jobs need an explicit compatibility or migration strategy. Restart workers with the new code after cutover. Restarting workers alone does not change already serialized payloads.

Test from a snapshot containing old polymorphic rows and queued payloads, rather than only using factories that create new data. Check relation reads, aliases, permissions and background work after the cutover. Retain the class mapping and rollback evidence until all old identities have been accounted for.

## What Mod reports

`mod:list --json` describes the current layout and discovered inventory. It does not compare an old application layout or query persisted identities. `mod:rename --dry-run --json` supplies a source-located review checklist for a recipe-owned model move, but persisted external values remain an application review responsibility. A whole-layout move needs the explicit pre-move mapping above; neither inventory nor the rename checklist proves that the database and queues are compatible.
