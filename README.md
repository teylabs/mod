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
- `kind()` also takes `fixed:`, `timestamped:`, `command:` (defaults to `mod:<kind>`; `false` for none), `priority:` and `using: fn (Kind $k) => $k->file()`. `relation()` takes `scope:`, `name:` and `policy:`.
- The active layout is checked when it is first used, and every problem is reported against the call that caused it.
