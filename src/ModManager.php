<?php

namespace Tey\Mod;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Tey\Mod\Discovery\DiscoveryCandidates;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Routing\ModRoutes;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

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

    /**
     * @param  (Closure(Scaffold): mixed)|(Closure(Part): mixed)  $recipe
     *
     * @api
     */
    public function scaffold(string $name, Closure $recipe): self
    {
        $this->scaffoldRegistry->register($name, $recipe);

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

    /** @param list<string>|null $only
     * @param  list<string>  $except
     *
     * @api
     */
    public function routes(?array $only = null, array $except = []): void
    {
        $call = 'unknown';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['file']) && ! in_array($frame['file'], [__FILE__, (new \ReflectionClass(Facade::class))->getFileName()], true)) {
                $call = $frame['file'].':'.($frame['line'] ?? 0);
                break;
            }
        }
        ($this->container ?? Container::getInstance())->make(ModRoutes::class)->load($only, $except, $call);
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
