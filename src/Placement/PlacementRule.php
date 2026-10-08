<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledRoot;

/**
 * Places one kind of artifact and, when it can, recognizes artifacts it would have placed.
 *
 * @internal
 */
interface PlacementRule
{
    public function kindId(): string;

    public function root(): CompiledRoot;

    /** Higher wins when several rules recognize the same input on reverse mapping. */
    public function priority(): int;

    /**
     * Dimension names this rule reads, in template order.
     *
     * @return list<string>
     */
    public function dimensions(): array;

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public function place(ArtifactKind $kind, string $name, PlacementContext $context, array $attributes): ResolvedArtifact;

    /** Whether the rule can be inverted. Opaque (callback) rules cannot. */
    public function isInvertible(): bool;

    /**
     * Every artifact this rule could have placed at the given class or path.
     *
     * @return list<ResolvedArtifact>
     */
    public function recognise(ArtifactKind $kind, string $subject, bool $isPath): array;
}
