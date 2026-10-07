<?php

namespace Tey\Mod\Discovery;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
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

        if ($inventory->ofType(DiscoveryType::Listener) !== []) {
            if ($app->bound('events')) {
                /** @var Dispatcher $events */
                $events = $app->make('events');
                $discovery->registerListeners($events);
            }

            $app->rebinding('events', static function (Application $app, Dispatcher $events) use ($discovery): void {
                $discovery->registerListeners($events);
            });
        }

        return $discovery;
    }

    /**
     * One discovery per application: registering the same preset and settings again is a no-op, anything else is an error.
     */
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
