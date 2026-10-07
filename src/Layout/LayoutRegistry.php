<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Exceptions\InvalidGeneratorSetup;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;
use Tey\Mod\Preset\Preset;

/**
 * The application's layouts by name: the built-in ones, extended or not,
 * and any defined with Mod::layout(). One per application (container singleton).
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

        $layout = new Layout($name);
        $this->builtIn->define($name, $layout);

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
     * Compile a layout for use. It is sealed: changing it afterwards is an error, never silently ignored.
     *
     * @throws InvalidLayout
     */
    public function compile(string $name): Preset
    {
        if (! $this->has($name)) {
            throw new InvalidGeneratorSetup(sprintf(
                'Layout [%s] is not defined. Use a built-in layout (%s) or define it with Mod::layout(\'%s\') in a service provider.',
                $name,
                implode(', ', $this->builtIn->names()),
                $name,
            ));
        }

        $layout = $this->layout($name);
        $layout->seal();

        return $layout->compile();
    }
}
