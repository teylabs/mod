<?php

namespace Tey\Mod\Discovery;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use ReflectionClass;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Preset\Preset;
use WeakReference;

/**
 * The single entry point that wires discovery into an application.
 *
 *     DiscoveryRegistrar::register($this->app, $preset, DiscoveryOptions::fromConfig(config('mod.discovery', [])));
 *
 * Call it from a service provider's register(). Providers register at once,
 * commands when that application's Artisan starts, listeners on the current
 * dispatcher and on any dispatcher that replaces it. Registering the same
 * preset again on the same application changes nothing.
 */
final class DiscoveryRegistrar
{
    public static function register(Application $app, Preset $preset, ?DiscoveryOptions $options = null): Discovery
    {
        $discovery = new Discovery($preset, $options ?? new DiscoveryOptions, $app->basePath());

        if ($app->bound(Discovery::class)) {
            return self::existing($app, $discovery);
        }

        $app->instance(Discovery::class, $discovery);

        $inventory = $discovery->inventory();
        self::warnAboutStaleCache($app, $discovery);

        foreach ($inventory->classes(DiscoveryType::Provider) as $provider) {
            $app->register($provider);
        }

        $commands = $inventory->classes(DiscoveryType::Command);

        if ($commands !== []) {
            // Artisan's bootstrappers are process-wide; only this application's Artisan receives these commands.
            $owner = WeakReference::create($app);

            Artisan::starting(static function (Artisan $artisan) use ($owner, $commands): void {
                if ($owner->get() !== null && $artisan->getLaravel() === $owner->get()) {
                    $artisan->resolveCommands($commands);
                }
            });
        }

        if ($inventory->ofType(DiscoveryType::Listener) !== [] || $inventory->ofType(DiscoveryType::Subscriber) !== []) {
            $skip = self::applicationListenerPaths($app);

            if ($app->bound('events')) {
                $events = $app->make('events');
                $discovery->registerListeners($events, $skip);
            }
            $app->rebinding('events', static function (Application $app, Dispatcher $events) use ($discovery, $skip): void {
                $discovery->registerListeners($events, $skip);
            });
        }

        self::loadMigrationDirectories($app, $preset, $inventory);

        return $discovery;
    }

    /**
     * One discovery per application: registering the same preset and settings again is a no-op, anything else is an error.
     */
    /**
     * A stale cache file that the scan policy ignored is worth knowing about:
     * every boot scans until the cache is rebuilt or removed.
     */
    private static function warnAboutStaleCache(Application $app, Discovery $discovery): void
    {
        $reason = $discovery->staleCacheReason();

        if ($reason === null) {
            return;
        }

        $message = sprintf(
            'mod: ignoring the stale discovery cache at [%s] (%s); scanning instead. Rebuild it with `php artisan mod:discovery-cache` or remove it with `php artisan mod:discovery-clear`.',
            $discovery->cache()->path,
            rtrim($reason, '.'),
        );

        if ($app->bound('log')) {
            $app->make('log')->warning($message);
        }

        if ($app->runningInConsole() && ! $app->runningUnitTests()) {
            fwrite(STDERR, $message.PHP_EOL);
        }
    }

    /**
     * The directories of every timestamped file kind (migrations placed by the
     * layout) join the migrator's paths, so `php artisan migrate` sees them;
     * the application's default database/migrations is Laravel's own.
     */
    private static function loadMigrationDirectories(Application $app, Preset $preset, Inventory $inventory): void
    {
        $default = realpath($app->databasePath('migrations'));
        $directories = [];

        foreach ($inventory->ofType(DiscoveryType::Directory) as $entry) {
            if (! $preset->hasKind($entry->kindId) || $preset->kind($entry->kindId)->namePolicy->kind !== NamePolicyKind::Timestamped) {
                continue;
            }

            $directory = rtrim($app->basePath(), '/\\').DIRECTORY_SEPARATOR.$entry->path;

            if ($default === false || realpath($directory) !== $default) {
                $directories[] = $directory;
            }
        }

        if ($directories === []) {
            return;
        }

        $load = static function (Migrator $migrator) use ($directories): void {
            foreach ($directories as $directory) {
                $migrator->path($directory);
            }
        };

        if ($app->resolved('migrator')) {
            $load($app->make('migrator'));
        }

        $app->afterResolving('migrator', static function (mixed $migrator) use ($load): void {
            if ($migrator instanceof Migrator) {
                $load($migrator);
            }
        });
    }

    /**
     * The directories the application's own event discovery covers, so mod
     * leaves the listeners there to Laravel whichever side registers first:
     * the registered EventServiceProvider's paths (`withEvents()` or
     * app/Listeners by default) when it discovers events, or the paths given
     * to `withEvents()` before that provider has registered. None when the
     * application has no event discovery.
     *
     * @return list<string>
     */
    public static function applicationListenerPaths(Application $app): array
    {
        $class = new ReflectionClass(EventServiceProvider::class);

        if ($class->hasProperty('shouldDiscoverEvents') && $class->getStaticPropertyValue('shouldDiscoverEvents') === false) {
            return [];
        }

        $provider = $app instanceof \Illuminate\Foundation\Application ? $app->getProvider(EventServiceProvider::class) : null;

        if ($provider instanceof EventServiceProvider) {
            if (! $provider->shouldDiscoverEvents()) {
                return [];
            }

            $paths = (fn (): mixed => $this->discoverEventsWithin())->call($provider);
        } else {
            $paths = $class->hasProperty('eventDiscoveryPaths') ? $class->getStaticPropertyValue('eventDiscoveryPaths') : [];
        }

        return array_values(array_filter(is_iterable($paths) ? [...$paths] : [], 'is_string'));
    }

    private static function existing(Application $app, Discovery $requested): Discovery
    {
        $existing = $app->make(Discovery::class);

        if ($existing->presetFingerprint() !== $requested->presetFingerprint()
            || $existing->definitionsFingerprint() !== $requested->definitionsFingerprint()
        ) {
            throw new ModException('Discovery is already registered for this application with a different layout or discovery settings.');
        }

        return $existing;
    }
}
