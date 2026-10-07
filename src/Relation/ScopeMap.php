<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Placement\PlacementContext;

/**
 * How the target's placement derives from the source's placement.
 */
final readonly class ScopeMap
{
    /**
     * @param  list<string>|null  $keep  null keeps every dimension
     */
    private function __construct(public ?array $keep) {}

    public static function same(): self
    {
        return new self(null);
    }

    /**
     * Keep only these dimensions (a slice request relating to a feature-scoped model drops "slice").
     *
     * @param  list<string>  $dimensions
     */
    public static function keep(array $dimensions): self
    {
        return new self($dimensions);
    }

    public function apply(PlacementContext $context): PlacementContext
    {
        return $this->keep === null ? $context : $context->only($this->keep);
    }
}
