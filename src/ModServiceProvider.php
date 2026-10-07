<?php

namespace Tey\Mod;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Exceptions\InvalidGeneratorSetup;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Preset\Preset;

class ModServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mod.php', 'mod');

        $this->app->singleton(LayoutRegistry::class);

        // The active layout compiles on first use, after every provider has booted
        // (Artisan::starting, the booted callback below), so Mod::layout() calls in
        // any provider's register() or boot() apply.
        $this->app->singleton(Preset::class, function (Application $app): Preset {
            return $this->activeLayout($app);
        });

        $this->app->singleton(GeneratorRegistry::class, function (Application $app): GeneratorRegistry {
            return new GeneratorRegistry((array) $app->make('config')->get('mod.generators', []));
        });

        $this->app->bind(ModMigrationCreator::class, function (Application $app): ModMigrationCreator {
            return new ModMigrationCreator($app->make('files'), $app->basePath('stubs'));
        });

        // Discovery, once every provider has booted: discovered providers register (and
        // boot) then, commands when Artisan starts, listeners on the dispatcher.
        // Disabled discovery binds and scans nothing.
        $this->app->booted(function (Application $app): void {
            if (! $this->discoveryEnabled()) {
                return;
            }

            DiscoveryRegistrar::register(
                $app,
                $app->make(Preset::class),
                DiscoveryOptions::fromConfig((array) $app->make('config')->get('mod.discovery', [])),
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/mod.php' => $this->app->configPath('mod.php')], 'mod-config');

        // mod:* commands, decided once when Artisan starts. Artisan's bootstrappers are
        // process-wide: act only for this provider's own application.
        Artisan::starting(function (Artisan $artisan): void {
            if ($artisan->getLaravel() !== $this->app) {
                return;
            }

            $this->registerGeneratorCommands($artisan);
            $this->registerDiscoveryCommands($artisan);
        });
    }

    /**
     * The layout `mod.layout` names. `mod.preset`, a raw internal preset
     * definition, is an undocumented test hook that wins when set.
     */
    private function activeLayout(Application $app): Preset
    {
        $config = $app->make('config');
        $definition = $config->get('mod.preset');

        if (is_array($definition)) {
            /** @var array<string, mixed> $definition */
            return Preset::fromArray($definition);
        }

        $name = $config->get('mod.layout', 'laravel');

        if (! is_string($name) || $name === '') {
            throw new InvalidGeneratorSetup('Config [mod.layout] must be a layout name such as "laravel".');
        }

        return $app->make(LayoutRegistry::class)->compile($name);
    }

    private function discoveryEnabled(): bool
    {
        return (bool) $this->app->make('config')->get('mod.discovery.enabled', true);
    }

    /**
     * mod:discovery-cache / mod:discovery-clear, hooked into optimize and
     * optimize:clear, only while mod:* commands and discovery are both on.
     * Laravel keeps the optimize hooks in a process-wide map keyed by
     * provider, so they are set or removed to match this application.
     */
    private function registerDiscoveryCommands(Artisan $artisan): void
    {
        $enabled = (bool) $this->app->make('config')->get('mod.commands', true)
            && $this->discoveryEnabled()
            && $this->app->bound(Discovery::class);

        if (! $enabled) {
            unset(static::$optimizeCommands['mod'], static::$optimizeClearCommands['mod']);

            return;
        }

        $artisan->resolveCommands([DiscoveryCacheCommand::class, DiscoveryClearCommand::class]);
        $this->optimizes(optimize: 'mod:discovery-cache', clear: 'mod:discovery-clear', key: 'mod');
    }

    /**
     * One mod:* command per layout kind, unless the host turned them off
     * (`mod.commands` false, or a layout defined ->withoutCommands()).
     */
    private function registerGeneratorCommands(Artisan $artisan): void
    {
        if (! (bool) $this->app->make('config')->get('mod.commands', true)) {
            return;
        }

        $preset = $this->app->make(Preset::class);

        if (! $preset->commandsEnabled()) {
            return;
        }

        foreach ($this->app->make(GeneratorRegistry::class)->commands($preset, $this->app) as $command) {
            $artisan->add($command);
        }
    }
}
