<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Artifact\ResolvedArtifact;

/**
 * @api
 */
final readonly class RelationResolution
{
    private function __construct(
        /** @api */
        public Relation $relation,
        /** @api */
        public ResolvedArtifact $source,
        /** @internal */
        public RelationStatus $status,
        /** @api */
        public ?ResolvedArtifact $target,
        /** @internal */
        public ?string $reason,
    ) {}

    /** @api */
    public static function resolved(Relation $relation, ResolvedArtifact $source, ResolvedArtifact $target): self
    {
        return new self($relation, $source, RelationStatus::Resolved, $target, null);
    }

    /** @internal */
    public static function unresolved(Relation $relation, ResolvedArtifact $source, string $reason): self
    {
        return new self($relation, $source, RelationStatus::Unresolved, null, $reason);
    }

    /** @api */
    public function isResolved(): bool
    {
        return $this->status === RelationStatus::Resolved;
    }

    /** @api */
    public function mode(): RelationMode
    {
        return $this->relation->mode;
    }
}
