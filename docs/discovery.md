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

To discover another file type as one of these, map it in `config/mod.php`:

```php
// config/mod.php
'discovery' => [
    'kinds' => ['console' => 'command'],
],
```

### Where Discovery Looks

A file type is discovered in its own folder, such as `src/Domain/<Domain>/Listeners`. With `discoverAnywhere: true`, it is discovered in every PHP file below its group folder, and `except:` skips folders below the group folder, such as `src/Domain/Knowledge/Tests`:

```php
// app/Providers/AppServiceProvider.php
Mod::layout('ddd')->kind('listener', in: '{domain+}/Listeners', discoverAnywhere: true, except: ['Tests']);
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

`Inventory::directories('migration')` still lists the folders.

## Factories and Policies

A model the layout places finds its factory and policy through the layout:

```php
use App\Modules\Knowledge\Models\Document;
use Illuminate\Support\Facades\Gate;

Document::factory();                 // App\Modules\Knowledge\Database\Factories\DocumentFactory
Gate::getPolicyFor(Document::class); // App\Modules\Knowledge\Policies\DocumentPolicy
```

The model needs no `newFactory()` method and the policy no `Gate::policy()` call. A policy your application registers for a model with `Gate::policy()` is kept. A factory resolver your application sets after mod (`Factory::guessFactoryNamesUsing()`) replaces mod's, as it would replace any earlier one. Turn either off with `'discovery.factories' => false` or `'discovery.policies' => false`.

## Caching

```bash
php artisan mod:discovery-cache   # also run by php artisan optimize
php artisan mod:discovery-clear   # also run by php artisan optimize:clear
```

With a cache present, mod registers from the cache without scanning. When the layout or the discovery settings change after the cache was written, the cache is ignored: mod scans instead and logs a warning naming both commands. Set `'discovery.on_stale_cache' => 'fail'` to stop the app booting instead.

Like Laravel's own caches, the discovery cache doesn't pick up new classes. After adding a provider, command or listener while the cache exists, run `php artisan optimize:clear`.

## Supplying Your Own Candidate Files

A package can supply the files discovery considers, for example to reuse an existing finder or skip generated folders. Turn the built-in registration off (`'discovery.enabled' => false`) and register discovery yourself once every provider has booted:

```php
// app/Providers/AppServiceProvider.php
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Placement\Root;
use Tey\Mod\Preset\Preset;

public function register(): void
{
    $this->app->booted(function ($app) {
        $options = DiscoveryOptions::fromConfig([...config('mod.discovery'), 'enabled' => true])
            ->withCandidates(fn (Root $root, string $basePath, DiscoveryDefinition $definition): iterable => [
                'app/Modules/Knowledge/Listeners/GenerateEmbeddings.php',
            ]);

        DiscoveryRegistrar::register($app, $app->make(Preset::class), $options);
    });
}
```

The candidates are paths relative to the application. The definition says which file type (and discovery type) is being collected, so candidates can be scoped per type. Mod still decides which candidates are registered, in what order, and how.
