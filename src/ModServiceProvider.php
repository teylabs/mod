<?php

namespace Tey\Mod;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Tey\Mod\Commands\BasesCommand;
use Tey\Mod\Commands\OtherLayoutCommand;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Generation\BaseWriter;
use Tey\Mod\Generation\ComposerPackageDetector;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Starters;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Resolution\ModelConventions;

class ModServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mod.php', 'mod');

        $this->app->singleton(LayoutRegistry::class);
        $this->app->singleton(StubRegistry::class, fn (): StubRegistry => Starters::register(new StubRegistry));
        $this->app->bind(BaseWriter::class, fn (Application $app): BaseWriter => new BaseWriter(
            $app->make('files'),
            $app->basePath(),
            self::basesPath($app),
            self::appNamespace($app),
        ));
        $this->app->singleton(PackageDetector::class, ComposerPackageDetector::class);
        $this->app->singleton(ModManager::class);

        // The active layout compiles on first use, after every provider has booted
        // (Artisan::starting, the booted callback below), so Mod::layout() calls in
        // any provider's register() or boot() apply.
        $this->app->singleton(CompiledLayout::class, function (Application $app): CompiledLayout {
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
                $app->make(CompiledLayout::class),
                DiscoveryOptions::fromConfig((array) $app->make('config')->get('mod.discovery', [])),
            );
        });
    }

    public function boot(): void
    {
        // Model::factory() through the layout's factory relation; a resolver registered
        // before this one is delegated to, one registered after it wins.
        if ($this->discoveryEnabled() && DiscoveryOptions::fromConfig((array) $this->app->make('config')->get('mod.discovery', []))->factories) {
            ModelConventions::register($this->app);
        }

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

        // Last, once every provider has booted, so a package's command of the same name wins.
        $this->app->booted(function (): void {
            Artisan::starting(function (Artisan $artisan): void {
                if ($artisan->getLaravel() === $this->app) {
                    $this->registerOtherLayoutCommands($artisan);
                }
            });
        });
    }

    /**
     * The layout `mod.layout` names. `mod.preset`, a raw internal preset
     * definition, is an undocumented test hook that wins when set.
     */
    private function activeLayout(Application $app): CompiledLayout
    {
        $config = $app->make('config');
        $definition = $config->get('mod.preset');

        if (is_array($definition)) {
            /** @var array<string, mixed> $definition */
            return CompiledLayout::fromArray($definition);
        }

        $name = $config->get('mod.layout', 'laravel');

        if (! is_string($name) || $name === '') {
            throw InvalidLayout::notNamed();
        }

        $registry = $app->make(LayoutRegistry::class);

        if ($registry->has($name) && ! $registry->layout($name)->isSealed()) {
            $registry->layout($name)->reserveBaseFolders($app->make(StubRegistry::class), self::basesPath($app));
        }

        return $registry->compile($name);
    }

    /**
     * `mod.bases_path`: where generated bases go, relative to the base path.
     */
    private static function basesPath(Application $app): string
    {
        $path = $app->make('config')->get('mod.bases_path', 'app/Support');

        return is_string($path) && trim($path, '/ ') !== '' ? trim($path, '/ ') : 'app/Support';
    }

    private static function appNamespace(Application $app): string
    {
        try {
            return $app instanceof \Illuminate\Foundation\Application ? $app->getNamespace() : 'App\\';
        } catch (\RuntimeException) {
            return 'App\\';
        }
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
     * A hidden placeholder for each mod:* command another built-in layout has
     * and nothing here registers: running it names the layouts that have it.
     */
    private function registerOtherLayoutCommands(Artisan $artisan): void
    {
        $config = $this->app->make('config');
        $layout = $config->get('mod.layout');

        if (! (bool) $config->get('mod.commands', true) || is_array($config->get('mod.preset')) || ! is_string($layout)) {
            return;
        }

        $preset = $this->app->make(CompiledLayout::class);

        if (! $preset->commandsEnabled()) {
            return;
        }

        $own = [];

        foreach ($preset->kinds() as $kind) {
            foreach ($kind->command === null ? [] : [$kind->command, ...$kind->aliases] as $command) {
                $own[$command] = true;
            }
        }

        foreach ($this->app->make(LayoutRegistry::class)->builtInCommands() as $command => $other) {
            if (! isset($own[$command]) && ! $artisan->has($command)) {
                $artisan->add(new OtherLayoutCommand($command, $other['kind'], $other['layouts'], $layout));
            }
        }
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

        $preset = $this->app->make(CompiledLayout::class);

        if (! $preset->commandsEnabled()) {
            return;
        }

        $artisan->resolveCommands([BasesCommand::class]);

        foreach ($this->app->make(GeneratorRegistry::class)->commands($preset, $this->app) as $command) {
            $artisan->resolveCommands([$command]);

            // A placement option that would shadow one of the command's own is left out; say so.
            foreach (method_exists($command, 'placementOptionIssues') ? $command->placementOptionIssues() : [] as $issue) {
                $this->app->make('log')->warning('mod layout: '.$issue->describe());
            }
        }
    }
}
