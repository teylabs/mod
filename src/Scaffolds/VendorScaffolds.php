<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Illuminate\Container\Container;
use InvalidArgumentException;

/** A scoped package scaffold registrar. @api */
final readonly class VendorScaffolds
{
    /** @internal */
    public function __construct(private string $vendor, private ScaffoldRegistry $registry)
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $vendor) !== 1) {
            throw new InvalidArgumentException('A scaffold vendor must contain lowercase letters, digits and hyphens, starting with a letter or digit.');
        }
    }

    /** @param (Closure(Scaffold): mixed)|(Closure(Part): mixed) $recipe
     * @api
     */
    public function scaffold(string $name, Closure $recipe, ?string $command = null): self
    {
        $this->registry->register($name, $recipe, vendor: $this->vendor, command: $command);

        return $this;
    }

    /** @param array<array-key, Closure|class-string> $recipes
     * @api
     */
    public function scaffolds(array $recipes): self
    {
        foreach ($recipes as $name => $recipe) {
            $command = null;
            if (is_string($recipe)) {
                $instance = Container::getInstance()->make($recipe);
                if (! is_callable($instance) || ! isset($instance->name) || ! is_string($instance->name)) {
                    throw new InvalidArgumentException('A scaffold class must be invokable and declare a string $name.');
                }
                $name = $instance->name;
                $command = $instance->command ?? null;
                if ($command !== null && ! is_string($command)) {
                    throw new InvalidArgumentException('A scaffold command must be a string.');
                }
                $recipe = Closure::fromCallable($instance);
            }
            if (! is_string($name)) {
                throw new InvalidArgumentException('Scaffold closures must be keyed by name.');
            }
            $this->scaffold($name, $recipe, $command);
        }

        return $this;
    }
}
