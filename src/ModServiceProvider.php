<?php

namespace Tey\Mod;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Commands\AutoloadCommand;
use Tey\Mod\Commands\BasesCommand;
use Tey\Mod\Commands\CreateTemplateCommand;
use Tey\Mod\Commands\DisabledScaffoldCommand;
use Tey\Mod\Commands\DisabledTemplateCommand;
use Tey\Mod\Commands\InstallCommand;
use Tey\Mod\Commands\ListCommand;
use Tey\Mod\Commands\OtherLayoutCommand;
use Tey\Mod\Commands\ScaffoldCommand;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryCache;
use Tey\Mod\Discovery\DiscoveryCandidates;
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
use Tey\Mod\Routing\RouteServiceRegistrar;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Templates\TemplateDiagnostics;
use Tey\Mod\Views\ViewNamespaceRegistrar;
use Throwable;

class ModServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mod.php', 'mod');

        RouteServiceRegistrar::register($this->app);

        $this->app->singleton(ScaffoldRegistry::class);
        $this->app->singleton(TemplateCatalog::class, function (Application $app): TemplateCatalog {
            $options = DiscoveryOptions::fromConfig((array) $app->make('config')->get('mod.discovery', []));
            $cache = new DiscoveryCache(Path::resolve($app->basePath(), $options->cachePath));

            return new TemplateCatalog($app->basePath(), $app->make(StubRegistry::class), ! $app->runningInConsole() && $options->enabled ? $cache->templates() : null);
        });
        $this->app->singleton(TemplateDiagnostics::class);
        $this->app->singleton(LayoutRegistry::class);
        $this->app->bind(Stack::class, fn (Application $app): Stack => new Stack($app->basePath()));
        $this->app->singleton(StubRegistry::class, fn (): StubRegistry => Starters::register(new StubRegistry));
        $this->app->bind(BaseWriter::class, fn (Application $app): BaseWriter => new BaseWriter(
            $app->make('files'),
            $app->basePath(),
            self::basesPath($app),
            self::appNamespace($app),
        ));
        $this->app->singleton(PackageDetector::class, ComposerPackageDetector::class);
        $this->app->singleton(DiscoveryCandidates::class);
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

            $options = DiscoveryOptions::fromConfig((array) $app->make('config')->get('mod.discovery', []));
            $candidates = $app->make(DiscoveryCandidates::class)->using;

            DiscoveryRegistrar::register(
                $app,
                $app->make(CompiledLayout::class),
                $candidates === null ? $options : $options->withCandidates($candidates),
            );
        });
    }

    public function boot(): void
    {
        $this->app->booted(fn (Application $app) => ViewNamespaceRegistrar::register($app));

        // Model::factory() through the layout's factory relation; a resolver registered
        // before this one is delegated to, one registered after it wins.
        if ($this->discoveryEnabled() && DiscoveryOptions::fromConfig((array) $this->app->make('config')->get('mod.discovery', []))->factories) {
            ModelConventions::register($this->app);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->make('events')->listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->app->make(TemplateDiagnostics::class)->report($event->input, $event->output);
        });
        // Symfony fails lookup before CommandStarting. Keep scan diagnostics visible
        // when Laravel reports that exception, without registering the skipped command.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable(function (CommandNotFoundException $exception): void {
                    $input = new ArgvInput;
                    $quiet = $input->hasParameterOption(['--quiet', '-q']);
                    $this->app->make(TemplateDiagnostics::class)->report($input, new ConsoleOutput($quiet ? OutputInterface::VERBOSITY_QUIET : OutputInterface::VERBOSITY_NORMAL));
                });
            }
        });

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
     * The layout `mod.layout` names. `mod.preset`, a raw layout definition,
     * is an @internal test hook that wins when set; it is not public API.
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

        return $registry->compile($name, $app->make(TemplateCatalog::class))->withStack($app->make(Stack::class));
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
     * mod:cache / mod:clear, hooked into optimize and
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
        $this->optimizes(optimize: 'mod:cache', clear: 'mod:clear', key: 'mod');
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

        try {
            $preset = $this->app->make(CompiledLayout::class);
        } catch (Throwable $exception) {
            if (! $exception instanceof InvalidLayout) {
                throw $exception;
            }

            // Keep mod:list available to report configuration errors with exit 1.
            return;
        }

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
                // resolve() adds a command instance on every supported Laravel (addCommand() where add() is deprecated).
                $artisan->resolve(new OtherLayoutCommand($command, $other['kind'], $other['layouts'], $layout));
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

        try {
            $preset = $this->app->make(CompiledLayout::class);
        } catch (Throwable $exception) {
            if (! $exception instanceof InvalidLayout) {
                throw $exception;
            }

            // Keep mod:list available to report configuration errors with exit 1.
            $artisan->resolveCommands([ListCommand::class]);

            return;
        }

        if (! $preset->commandsEnabled()) {
            return;
        }

        $artisan->resolveCommands([ListCommand::class, BasesCommand::class, AutoloadCommand::class, CreateTemplateCommand::class, InstallCommand::class]);

        foreach ($this->app->make(GeneratorRegistry::class)->commands($preset, $this->app) as $command) {
            $artisan->resolveCommands([$command]);

            // A placement option that would shadow one of the command's own is left out; say so.
            foreach (method_exists($command, 'placementOptionIssues') ? $command->placementOptionIssues() : [] as $issue) {
                $this->app->make('log')->warning('mod layout: '.$issue->describe());
            }
        }

        foreach ($this->app->make(TemplateCatalog::class)->conflicts() as $command => $warning) {
            if (! $artisan->has($command)) {
                $artisan->resolve(new DisabledTemplateCommand($command, $warning));
            }
        }

        $registry = $this->app->make(ScaffoldRegistry::class);
        $layoutName = $this->app->make('config')->get('mod.layout', 'laravel');
        $overrides = is_string($layoutName) && $this->app->make(LayoutRegistry::class)->has($layoutName)
            ? $this->app->make(LayoutRegistry::class)->layout($layoutName)->scaffoldRecipes() : [];
        foreach ($registry->resolve($preset, is_string($layoutName) ? $layoutName : 'layout', $overrides, [...array_keys($artisan->all()), 'mod:cache', 'mod:clear', 'mod:list']) as $name => $recipe) {
            $artisan->resolve(new ScaffoldCommand($name, $recipe, $preset));
        }
        foreach ($registry->problems() as $name => $warning) {
            $this->app->make('log')->warning($warning);
            if (! $artisan->has('mod:'.$name)) {
                $artisan->resolve(new DisabledScaffoldCommand($name, $warning));
            }
        }

    }
}
