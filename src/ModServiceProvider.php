<?php

namespace Tey\Mod;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Command\Command;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\PresetFactory;
use Tey\Mod\Preset\Preset;

class ModServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mod.php', 'mod');

        $this->app->singleton(Preset::class, function (Application $app): Preset {
            return (new PresetFactory($app))->make($app->make('config')->get('mod.preset'));
        });

        $this->app->singleton(GeneratorRegistry::class, function (Application $app): GeneratorRegistry {
            /** @var array<string, class-string<GeneratorAdapter&Command>> $overrides */
            $overrides = (array) $app->make('config')->get('mod.generators', []);

            return new GeneratorRegistry($overrides);
        });

        $this->app->bind(ModMigrationCreator::class, function (Application $app): ModMigrationCreator {
            return new ModMigrationCreator($app->make('files'), $app->basePath('stubs'));
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/mod.php' => $this->app->configPath('mod.php')], 'mod-config');

        // Generation: mod:* commands, read once when Artisan starts.
        Artisan::starting(function (Artisan $artisan): void {
            $this->registerGeneratorCommands($artisan);
        });

        // DISCOVERY: the lead wires M2.3 discovery registration here.
    }

    /**
     * One mod:* command per preset kind, unless the host turned them off
     * (`mod.commands` false, or a preset that disables commands).
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
