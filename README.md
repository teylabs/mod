# tey/mod

**Private, experimental. Not released.**

`tey/mod` is a Laravel-aware generation and discovery engine that does not assume any particular application layout. Given a declared layout (ordinary Laravel, feature-first, vertical slices, type-first, or `app/Modules/<Module>`), it places generated artifacts using Laravel's native generators, resolves related artifacts (a model's factory, a controller's form requests), maps existing files back to the layout, and discovers providers, commands and listeners with explicit provenance and a cacheable inventory. Opinionated packages can then be built as thin layouts on top of it.

## Choosing a layout

`config/mod.php` names the active layout: `laravel` (the default, placing files exactly like `make:*`), `features`, `slices`, `type-first`, `modules`, or any name you define.

```php
'layout' => 'modules',
```

## Defining or extending a layout

Define a layout as one fluent chain from any service provider's `register()` or `boot()` (for example `AppServiceProvider::boot()`). Calling `Mod::layout()` with a built-in name extends that layout: repeating a kind, root or relation id overrides the arguments you pass and keeps the rest.

```php
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;

Mod::layout('ddd')
    ->root('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r
        ->kind('model', in: '{domain}/Models')
        ->kind('action', in: '{domain}/Actions'))
    ->root('app', 'App\\', 'app', fn (Root $r) => $r
        ->kind('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
    ->kind('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
    ->relation('factory', from: 'model', to: 'factory')
    ->exclude('App\\Support\\');

Mod::layout('modules')->kind('job', in: 'Modules/{module}/Jobs');
```

- `in:` is the kind's path below its root. A `root:` prefix picks another declared root. Without a prefix, the kind uses the enclosing `root()` closure's root, or else the first declared root.
- Placeholders such as `{domain}` (required) and `{feature?}` (optional) are the layout's placement dimensions, in order of first appearance. That order is the order of values in `--in=Billing/CreateInvoice`.
- A `{name+}` placeholder spans one or more folders (`{group+}` places `App\Billing\Invoicing\…` for a value of `Billing/Invoicing`); on the command line the folders inside such a value are separated by dots: `--in=Billing.Invoicing`.
- `kind()` also takes `fixed:`, `timestamped:`, `command:` (defaults to `mod:<kind>`; `false` for none), `priority:`, `nested: true` (the kind accepts nested names such as `Billing/Invoice`, placing the folders below its own folder exactly like `make:model Billing/Invoice`), `discover: 'anywhere'` with `except: [...]` (see Discovery) and `using: fn (Kind $k) => $k->file()`. `relation()` takes `scope:`, `name:` and `policy:`; a scope of `['nested' => 'drop']` stops the source's nested folders carrying over to the target (they do by default: `Models/Archived/Invoice` relates to `Policies/Archived/InvoicePolicy`).
- `exclude('App\\Support\\')` (a namespace or a path inside a declared root) marks a corner no kind owns: mod never places anything there, reverse mapping reports its classes as not owned, and discovery skips it.
- `relation(name: ...)` says how the target's name derives from the source's: `'explicit'` (always named by the caller) or a map of `strip-suffix`, `prefix` and `suffix`; the target kind's own name policy (`suffix:`/`fixed:`) still applies afterwards.
- The active layout is checked when it is first used, and every problem is reported against the call that caused it.

## Discovery

Discovery finds the providers, Artisan commands, listeners and event subscribers your layout places and registers them with Laravel, with a cache for production (`php artisan optimize` writes it through `mod:discovery-cache`). Kinds whose id is `provider`, `command`, `listener` or `subscriber` are discovered as that type by default; map other kinds in `config/mod.php` (`'discovery.kinds' => ['console' => 'command', 'migration' => 'directory']`).

- By default a kind is discovered in its own folder only. `discoverAnywhere: true` widens that to every PHP file below the kind's dimension folders, for example every `ServiceProvider` anywhere under `src/<Group>/`, while `except: ['Tests', 'Database/Migrations']` names folders (relative to the dimension folder) to skip. What the class really is still decides: a plain class is never registered, and such candidates are not reported as rejections. Reverse mapping and generation stay folder-exact.
- A `subscriber` is a class with a public `subscribe()` taking one parameter, registered through `Event::subscribe()`; a class that both listens and subscribes registers once, as a subscriber.
- Listeners and subscribers are registered once per dispatcher and never twice: what Laravel's own event discovery covers (`app/Listeners`, or the paths given to `withEvents()`), its events cache or a manual `listen()` already holds is left alone.
- Kinds placed by a callback (`using: fn (Kind $k) => $k->place(...)`) are discovered in their own folder only; `discoverAnywhere` is for template kinds.
- A stale cache file (the layout or the discovery settings changed since `mod:discovery-cache`) is ignored and discovery scans instead, with a warning naming `mod:discovery-cache` and `mod:discovery-clear`; set `'discovery.on_stale_cache' => 'fail'` to refuse to boot instead. `php artisan optimize:clear` removes the cache.
- The `directory` type collects, for a file kind such as `migration`, the directories its template binds that hold at least one file (`Inventory::directories('migration')`), ready for `loadMigrationsFrom()`.
- A host may supply the candidate files itself, for example to reuse an existing finder or skip generated subtrees: `DiscoveryOptions::fromConfig(...)->withCandidates(fn (Root $root, string $basePath, DiscoveryDefinition $definition): iterable => [...relative .php paths...])`; the definition lets a host scope candidates per discovered kind. Ownership, eligibility, ordering and registration stay with mod; the cache fingerprint records that a custom source is in use.

## Building your own generators on mod's adapters

Every `mod:*` command is a thin subclass of the matching Laravel `make:*` command (`Tey\Mod\Commands\ModelCommand`, `ControllerCommand`, `RequestCommand`, `FactoryCommand`, `MigrationCommand`, …) that only places the output. A package with its own command catalog can extend those adapters instead of the native commands and override a few protected hooks:

- Placement: `mod:model Billing:Invoice` is `mod:model Invoice --in=Billing` on every adapter. The name is split at its first colon; the left side is the placement in `--in` syntax (`/` between groups, `.` inside a `{group+}` value), the right side is the name (nested names allowed where the kind is `nested`). Giving both the prefix and `--in` is refused naming both; a prefix on a layout without placement groups is refused with a hint to drop it. A host may override `placementInput()` (return placement in `--in` syntax, e.g. from its own option or prompt), `placementContext()`, and `placementOptionName()` (`null` to add no `--in` option; child commands then receive the prefix form through `argumentsFor()`).
- Preset and kind: commands registered through the layout are bound with `forKind()`. A host that registers its own classes overrides `resolvePreset()` and `kindId()` instead, so each invocation can pick its preset and kind.
- Collisions: `collisionPolicy()` returns `CollisionPolicy::Refuse` (mod diagnoses the whole plan before writing) or `CollisionPolicy::Native` (the native generator's own "already exists" check and `--force` decide).
- Lifecycle: `plansEagerly()` (default `true`) plans in `execute()` before the native `handle()`; return `false` and call `resolvePlan()` from your own `handle()` to plan after your preparation. `beforeGeneration(GenerationPlan $plan)` and `afterGeneration(GenerationPlan $plan, int $exitCode)` run around the native write. The same hooks exist on `MigrationCommand`, whose `nativePathAllowed()` additionally lets `--path`/`--realpath` through natively instead of refusing them.
- A layout declared `withoutCommands()` registers no `mod:*` command; its kinds keep their `command:` names for the host to dispatch by, and several kinds may then share one.
- Output: `reportRefusal(ModException $e): int` and `reportReference(ResolvedArtifact $target)` are the only places the adapters print on their own; the native success message is unchanged.
