<?php

namespace Tey\Mod;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Tey\Mod\Database\GroupConnections;
use Tey\Mod\Discovery\DiscoveryCandidates;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Install\Wiring;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Pages\PageEntry;
use Tey\Mod\Pages\PageRoots;
use Tey\Mod\Routing\ModRoutes;
use Tey\Mod\Routing\RegistersRoutes;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Scaffolds\VendorScaffolds;
use Tey\Mod\Support\Stack;

/**
 * What the Mod facade reaches: layouts, stubs and generators, callable from
 * any package's service provider.
 *
 *  reached through the Mod facade
 *
 * @api
 */
final readonly class ModManager
{
    /** @internal */
    public function __construct(
        private LayoutRegistry $layouts,
        private StubRegistry $stubs,
        private GeneratorRegistry $generators,
        private ?Container $container = null,
        private DiscoveryCandidates $candidates = new DiscoveryCandidates,
        private ScaffoldRegistry $scaffoldRegistry = new ScaffoldRegistry,
    ) {}

    /**
     * Supply the files discovery considers, instead of scanning each root:
     * fn (CompiledRoot $root, string $basePath, DiscoveryDefinition $definition): iterable
     * returns paths relative to the application. Mod still decides which of
     * them are registered, in what order, and how.
     *
     * @param  Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>  $candidates
     *
     * @api
     */
    public function discoverUsing(Closure $candidates): self
    {
        $this->candidates->using = $candidates;

        return $this;
    }

    /**
     * The active layout (config `mod.layout`), compiled: its kinds, roots,
     * dimensions and placement options.
     *
     * @api
     */
    public function current(): CompiledLayout
    {
        return ($this->container ?? Container::getInstance())->make(CompiledLayout::class);
    }

    /**
     * Define a layout, or extend a built-in or defined one.
     *
     * @api
     */
    public function layout(string $name): Layout
    {
        return $this->layouts->layout($name);
    }

    /** @api */
    public function vendor(string $id): VendorScaffolds
    {
        return new VendorScaffolds($id, $this->scaffoldRegistry);
    }

    /**
     * @param  (Closure(Scaffold): mixed)|(Closure(Part): mixed)  $recipe
     *
     * @api
     */
    public function scaffold(string $name, Closure $recipe, ?string $command = null): self
    {
        $this->scaffoldRegistry->register($name, $recipe, command: $command);

        return $this;
    }

    /**
     * @param  array<array-key, Closure|class-string>  $recipes
     *
     * @api
     */
    public function scaffolds(array $recipes): self
    {
        foreach ($recipes as $name => $recipe) {
            if (is_string($recipe)) {
                $instance = ($this->container ?? Container::getInstance())->make($recipe);
                if (! is_callable($instance) || ! isset($instance->name) || ! is_string($instance->name)) {
                    throw new \InvalidArgumentException('A scaffold class must be invokable and declare a string $name.');
                }
                $name = $instance->name;
                $recipe = Closure::fromCallable($instance);
            }
            if (! is_string($name)) {
                throw new \InvalidArgumentException('Scaffold closures must be keyed by name.');
            }
            $this->scaffold($name, $recipe);
        }

        return $this;
    }

    /** Declare the database connection for a complete layout group. @api */
    public function connection(string $group, string $connection): self
    {
        ($this->container ?? Container::getInstance())->make(GroupConnections::class)->declare($group, $connection);

        return $this;
    }

    /** Register a package route directory without loading it. @api */
    public function routeFiles(string $group, string $directory, ?string $apiPrefix = null): self
    {
        ($this->container ?? Container::getInstance())->make(ModRoutes::class)->files($group, $directory, $apiPrefix);

        return $this;
    }

    /**
     * @param  class-string<RegistersRoutes>  $class
     *
     * @api
     */
    public function routeRegistrar(string $group, string $class, ?string $apiPrefix = null): self
    {
        ($this->container ?? Container::getInstance())->make(ModRoutes::class)->registrar($group, $class, $apiPrefix);

        return $this;
    }

    /** @param list<string>|null $only
     * @param  list<string>  $except
     *
     * @api
     */
    public function routes(?array $only = null, array $except = [], string $apiPrefix = 'api'): void
    {
        $call = 'unknown';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['file']) && ! in_array($frame['file'], [__FILE__, (new \ReflectionClass(Facade::class))->getFileName()], true)) {
                $call = $frame['file'].':'.($frame['line'] ?? 0);
                break;
            }
        }
        ($this->container ?? Container::getInstance())->make(ModRoutes::class)->load($only, $except, $call, $apiPrefix);
    }

    /** Declare a package's Inertia page namespace and absolute directory.
     * @param  list<string>|null  $entries
     *
     * @api
     */
    public function pages(string $namespace, string $directory, ?array $entries = null): self
    {
        ($this->container ?? Container::getInstance())->make(PageRoots::class)->register($namespace, $directory, $entries);

        return $this;
    }

    /** Declare frontend wiring for an explicit installation step. @api */
    public function wiring(): Wiring
    {
        return ($this->container ?? Container::getInstance())->make(Wiring::class);
    }

    /** Read the application's declared frontend stack. @api */
    public function stack(): Stack
    {
        return ($this->container ?? Container::getInstance())->make(Stack::class);
    }

    /** Map an Inertia page name to its project-relative Vite entry. @api */
    public function pageEntry(string $page, string $extension): string
    {
        return ($this->container ?? Container::getInstance())->make(PageEntry::class)->path($page, $extension);
    }

    /** @api */
    public function hasLayout(string $name): bool
    {
        return $this->layouts->has($name);
    }

    /**
     * @return list<string>
     *
     * @api
     */
    public function layouts(): array
    {
        return $this->layouts->names();
    }

    /**
     * Stubs packages register for a kind: Mod::stubs()->for('record', Stub::file(...)).
     *
     * @api
     */
    public function stubs(): StubRegistry
    {
        return $this->stubs;
    }

    /**
     * Generator commands by kind: Mod::generators()->use('record', RecordCommand::class).
     *
     * @api
     */
    public function generators(): GeneratorRegistry
    {
        return $this->generators;
    }
}
