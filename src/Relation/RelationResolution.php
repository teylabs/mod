<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Artifact\ResolvedArtifact;

final readonly class RelationResolution
{
    private function __construct(
        public Relation $relation,
        public ResolvedArtifact $source,
        public RelationStatus $status,
        public ?ResolvedArtifact $target,
        public ?string $reason,
    ) {}

    public static function resolved(Relation $relation, ResolvedArtifact $source, ResolvedArtifact $target): self
    {
        return new self($relation, $source, RelationStatus::Resolved, $target, null);
    }

    public static function unresolved(Relation $relation, ResolvedArtifact $source, string $reason): self
    {
        return new self($relation, $source, RelationStatus::Unresolved, null, $reason);
    }

    public function isResolved(): bool
    {
        return $this->status === RelationStatus::Resolved;
    }

    public function mode(): RelationMode
    {
        return $this->relation->mode;
    }
}
