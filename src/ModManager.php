<?php

namespace Tey\Mod;

use Illuminate\Container\Container;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;

/**
 * What the Mod facade reaches: layouts, stubs and generators, callable from
 * any package's service provider.
 */
final readonly class ModManager
{
    public function __construct(
        private LayoutRegistry $layouts,
        private StubRegistry $stubs,
        private GeneratorRegistry $generators,
        private ?Container $container = null,
    ) {}

    /**
     * The active layout (config `mod.layout`), compiled: its kinds, roots,
     * dimensions and placement options.
     */
    public function current(): CompiledLayout
    {
        return ($this->container ?? Container::getInstance())->make(CompiledLayout::class);
    }

    /**
     * Define a layout, or extend a built-in or defined one.
     */
    public function layout(string $name): Layout
    {
        return $this->layouts->layout($name);
    }

    public function hasLayout(string $name): bool
    {
        return $this->layouts->has($name);
    }

    /**
     * @return list<string>
     */
    public function layouts(): array
    {
        return $this->layouts->names();
    }

    /**
     * Stubs packages register for a kind: Mod::stubs()->for('record', Stub::file(...)).
     */
    public function stubs(): StubRegistry
    {
        return $this->stubs;
    }

    /**
     * Generator commands by kind: Mod::generators()->use('record', RecordCommand::class).
     */
    public function generators(): GeneratorRegistry
    {
        return $this->generators;
    }
}
