# Discovery

Mod registers the providers, Artisan commands, event listeners and event subscribers your layout places, adds its migration folders to Laravel's migrator, and finds the factory and policy of each model it places. This page covers what is discovered where, caching, and supplying your own candidate files.

## What Is Discovered

| File type | Registered as |
| --- | --- |
| `provider` | a service provider |
| `command` | an Artisan command |
| `listener` | an event listener, for the events its `handle()` method accepts |
| `subscriber` | an event subscriber: a class with a public `subscribe()` taking one parameter, registered through `Event::subscribe()` |
| `migration` | a migration folder, added to the migrator |

Discovery runs after every provider has booted. Only classes that really are providers, commands, listeners or subscribers are registered; everything else is skipped.

Route files, views and translations aren't discovered. A module loads its routes from a provider of its own, which is discovered; [Module Routes](../README.md#module-routes) shows how.

A subscriber in a `Listeners` folder is treated the way Laravel's own event discovery treats it: its typed `handle*()` methods are registered as listeners, and `subscribe()` isn't called. To register subscribers, give them a `subscriber` file type of their own:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

Mod::layout('modules')->kind('subscriber', in: 'Modules/{module}/Subscribers');
```

### Discovering Another File Type

`discovery.kinds` is keyed by file type id. The built-in layouts call their Artisan commands `command`, so those are discovered already. To discover a file type of your own, map its id to what it is discovered as:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

Mod::layout('modules')->kind('handler', in: 'Modules/{module}/Handlers');
```

```php
// config/mod.php
'discovery' => [
    'kinds' => ['handler' => 'listener'],
],
```

A key that isn't a file type of the active layout stops the app with an error listing the layout's file type ids.

### Where Discovery Looks

A file type is discovered in its own folder, such as `src/Domain/<Domain>/Listeners`. With `discover: 'anywhere'`, it is discovered in every PHP file below its group folder, and `discoverExcept:` skips folders below the group folder, such as `src/Domain/Knowledge/Tests`:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Facades\Mod;

Mod::layout('ddd')->kind('listener', in: '{domain+}/Listeners', discover: 'anywhere', discoverExcept: ['Tests']);
```

A file type placed by a callback (`using:`) is discovered in its own folder only.

### Listeners Are Registered Once

A listener is never registered twice. Whatever Laravel's own event discovery covers (`app/Listeners`, or the paths given to `withEvents()`), its events cache, or a manual `Event::listen()` already holds is left alone.

## Migrations

The folders a timestamped file type such as `migration` writes to are added to Laravel's migrator, so `php artisan migrate`, `migrate:rollback` and `migrate:status` include them. Laravel's own `database/migrations` is left to Laravel.

To manage migration folders yourself, turn this off:

```php
// config/mod.php
'discovery' => [
    'kinds' => ['migration' => false],
],
```

## Factories and Policies

A model the layout places finds its factory and policy through the layout:

```php
use App\Modules\Knowledge\Models\Document;
use Illuminate\Support\Facades\Gate;

Document::factory();                 // App\Modules\Knowledge\Database\Factories\DocumentFactory
Gate::getPolicyFor(Document::class); // App\Modules\Knowledge\Policies\DocumentPolicy
```

Discovery doesn't need a `newFactory()` method on the model or a `Gate::policy()` call. `mod:model -f` still writes `newFactory()`, so the model keeps working without mod. Both lookups read the discovered classes, so `'discovery.enabled' => false` turns them off too. A policy your application registers for a model with `Gate::policy()` is kept. A factory resolver your application sets after mod (`Factory::guessFactoryNamesUsing()`) replaces mod's, as it would replace any earlier one. Turn either off with `'discovery.factories' => false` or `'discovery.policies' => false`.

## Caching

```bash
php artisan mod:cache   # also run by php artisan optimize
php artisan mod:clear   # also run by php artisan optimize:clear
```

`mod:cache` prints what it registered, and a second line for any files it found but didn't register. For the two modules in [Self-Contained Modules](../README.md#self-contained-modules), with the Knowledge provider that loads its routes:

```text
INFO  Discovery cached in [bootstrap/cache/mod-discovery.php]: 1 providers, 0 commands, 1 listeners, 0 subscribers, 2 directories, 6 rejected.
Rejected files were found but not registered: 6 placed by no file type (helpers and plain classes; nothing to do). Run with -v to list them.
```

| Reason | What to do |
| --- | --- |
| placed by no file type | nothing: files no file type of the layout owns, such as `app/Models/User.php`, the base classes in `app/Support` and a module's `routes/web.php` |
| in a discovered folder but not a provider, command, listener or subscriber | check it: for example, a listener whose `handle()` has no typed event parameter |
| placed by more than one file type | give one file type a `priority:` |
| placed by a callback, so its file type cannot be told | nothing, unless it should be discovered |

`php artisan mod:cache -v` lists each rejected file with its reason.

With a cache present, mod registers from the cache without scanning. When the layout or the discovery settings change after the cache was written, the cache is ignored: mod scans instead and logs a warning naming both commands. Set `'discovery.on_stale_cache' => 'fail'` to stop the app booting instead.

Like Laravel's own caches, the discovery cache doesn't pick up new classes. After adding a provider, command or listener while the cache exists, run `php artisan optimize:clear`.

## Supplying Your Own Candidate Files

A package can supply the files discovery considers, for example to reuse an existing finder or skip generated folders. Pass a callback to `Mod::discoverUsing()` in a provider's `boot()`:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledRoot;

public function boot(): void
{
    Mod::discoverUsing(fn (CompiledRoot $root, string $basePath, DiscoveryDefinition $definition): iterable => [
        'app/Modules/Knowledge/Listeners/GenerateEmbeddings.php',
    ]);
}
```

The callback replaces the scan of each root. It returns paths relative to the application. The definition says which file type (and discovery type) is being collected, so candidates can be scoped per type. Mod still decides which candidates are registered, in what order, and how, and factory and policy lookup stay on.
