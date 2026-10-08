<?php

namespace Tey\Mod\Artifact;

use Tey\Mod\Placement\PlacementContext;

/**
 * What a caller asks for: a kind, a name, an explicit placement and attributes.
 *
 * Attributes carry caller-supplied inputs that keep resolution pure, such as
 * the timestamp of a migration.
 *
 * @internal
 */
final readonly class ArtifactRequest
{
    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public function __construct(
        public string $kindId,
        public string $name,
        public PlacementContext $context,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public static function for(string $kindId, string $name, ?PlacementContext $context = null, array $attributes = []): self
    {
        return new self($kindId, $name, $context ?? PlacementContext::none(), $attributes);
    }

    public function withContext(PlacementContext $context): self
    {
        return new self($this->kindId, $this->name, $context, $this->attributes);
    }
}
