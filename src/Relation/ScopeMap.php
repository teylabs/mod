<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Placement\PlacementContext;

/**
 * How the target's placement derives from the source's placement, and
 * whether the source's nested folders carry over to the target (they do by
 * default: Models/Archived/Invoice → Policies/Archived/InvoicePolicy).
 *
 * @internal relation machinery behind RelationResolver.
 */
final readonly class ScopeMap
{
    /**
     * @param  list<string>|null  $keep  null keeps every dimension
     */
    private function __construct(
        public ?array $keep,
        public bool $keepNested = true,
        public ?string $nameDimension = null,
    ) {}

    public static function same(bool $keepNested = true, ?string $nameDimension = null): self
    {
        return new self(null, $keepNested, $nameDimension);
    }

    /**
     * Keep only these dimensions (a slice request relating to a feature-scoped model drops "slice").
     *
     * @param  list<string>  $dimensions
     */
    public static function keep(array $dimensions, bool $keepNested = true, ?string $nameDimension = null): self
    {
        return new self($dimensions, $keepNested, $nameDimension);
    }

    /** A named dimension can take the source stem when the source has no value for it. */
    public function apply(PlacementContext $context, ?string $sourceName = null): PlacementContext
    {
        $mapped = $this->keep === null ? $context : $context->only($this->keep);

        if ($this->nameDimension !== null && $sourceName !== null && ! $mapped->has($this->nameDimension)) {
            $mapped = $mapped->with($this->nameDimension, $sourceName);
        }

        return $mapped;
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
