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

    /**
     * The collision in plain words: "app/Models/Invoice.php already exists."
     */
    public function message(): string
    {
        return $this->kind === CollisionKind::Path
            ? $this->artifact->path().' already exists.'
            : "The class {$this->subject} already exists.";
    }

    public function describe(): string
    {
        return sprintf('%s collision: %s already exists (%s)', $this->kind->value, $this->subject, $this->artifact->describe());
    }
}
