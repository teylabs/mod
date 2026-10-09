<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;

/**
 * The application's layouts by name: the built-in ones, extended or not,
 * and any defined with Mod::layout(). One per application (container singleton).
 *
 * @internal
 */
final class LayoutRegistry
{
    /** @var array<string, Layout> */
    private array $layouts = [];

    public function __construct(private readonly BuiltInLayouts $builtIn = new BuiltInLayouts) {}

    /**
     * The layout of this name, to define or extend. A built-in layout starts
     * from its shipped definition; any other name starts empty.
     */
    public function layout(string $name): Layout
    {
        if (isset($this->layouts[$name])) {
            return $this->layouts[$name];
        }

        $layout = new Layout($name, $this);
        $this->builtIn->define($name, $layout);
        $layout->beginChain();

        return $this->layouts[$name] = $layout;
    }

    public function has(string $name): bool
    {
        return isset($this->layouts[$name]) || in_array($name, $this->builtIn->names(), true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...$this->builtIn->names(), ...array_keys($this->layouts)]));
    }

    /**
     * Every mod:* command name (aliases included) of the built-in layouts as
     * shipped, with the file type it generates and the layouts that have it.
     *
     * @return array<string, array{kind: string, layouts: list<string>}>
     */
    public function builtInCommands(): array
    {
        $fresh = new self($this->builtIn);
        $commands = [];

        foreach ($this->builtIn->names() as $name) {
            foreach ($fresh->compile($name)->kinds() as $kind) {
                foreach ($kind->command === null ? [] : [$kind->command, ...$kind->aliases] as $command) {
                    $commands[$command] ??= ['kind' => $kind->id, 'layouts' => []];
                    $commands[$command]['layouts'][] = $name;
                }
            }
        }

        return $commands;
    }

    /**
     * Compile a layout for use. It is sealed: changing it afterwards is an error, never silently ignored.
     *
     * @throws InvalidLayout
     */
    public function compile(string $name): CompiledLayout
    {
        if (! $this->has($name)) {
            throw InvalidLayout::notDefined($name, $this->builtIn->names());
        }

        $layout = $this->layout($name);
        $layout->seal();

        return $layout->compile();
    }
}
