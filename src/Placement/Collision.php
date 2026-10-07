<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ResolvedArtifact;

final readonly class Collision
{
    public function __construct(
        public CollisionKind $kind,
        public string $subject,
        public ResolvedArtifact $artifact,
    ) {}

    public function describe(): string
    {
        return sprintf('%s collision: %s already exists (%s)', $this->kind->value, $this->subject, $this->artifact->describe());
    }
}
