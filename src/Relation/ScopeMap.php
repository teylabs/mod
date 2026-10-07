<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Placement\PlacementContext;

/**
 * How the target's placement derives from the source's placement, and
 * whether the source's nested folders carry over to the target (they do by
 * default: Models/Archived/Invoice → Policies/Archived/InvoicePolicy).
 */
final readonly class ScopeMap
{
    /**
     * @param  list<string>|null  $keep  null keeps every dimension
     */
    private function __construct(
        public ?array $keep,
        public bool $keepNested = true,
    ) {}

    public static function same(bool $keepNested = true): self
    {
        return new self(null, $keepNested);
    }

    /**
     * Keep only these dimensions (a slice request relating to a feature-scoped model drops "slice").
     *
     * @param  list<string>  $dimensions
     */
    public static function keep(array $dimensions, bool $keepNested = true): self
    {
        return new self($dimensions, $keepNested);
    }

    public function apply(PlacementContext $context): PlacementContext
    {
        return $this->keep === null ? $context : $context->only($this->keep);
    }

    /**
     * The nested folders the target inherits from the source.
     *
     * @param  list<string>  $nested
     * @return list<string>
     */
    public function applyNested(array $nested): array
    {
        return $this->keepNested ? $nested : [];
    }
}
