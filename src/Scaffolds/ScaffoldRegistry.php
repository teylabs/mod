<?php

namespace Tey\Mod\Scaffolds;

use Closure;

/** Recipes are evaluated immediately so include() always takes a snapshot. */
final class ScaffoldRegistry
{
    /** @var array<string, Scaffold> */
    private array $recipes = [];

    /** @param Closure(Scaffold): mixed $recipe */
    public function register(string $name, Closure $recipe): self
    {
        $this->recipes[$name] = $this->build($recipe);

        return $this;
    }

    /** @param Closure(Scaffold): mixed $recipe */
    public function build(Closure $recipe): Scaffold
    {
        $scaffold = new Scaffold(fn (string $name): ?Scaffold => $this->get($name));
        $recipe($scaffold);

        return $scaffold;
    }

    public function get(string $name): ?Scaffold
    {
        return $this->recipes[$name] ?? null;
    }

    /** @return array<string, Scaffold> */
    public function all(): array
    {
        return $this->recipes;
    }
}
