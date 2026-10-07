<?php

namespace Tey\Mod\Artifact;

use Tey\Mod\Placement\PlacementContext;

/**
 * The outcome of placing a request: kind, placement, requested name and identity.
 */
final readonly class ResolvedArtifact
{
    public function __construct(
        public ArtifactKind $kind,
        public PlacementContext $context,
        public string $name,
        public ArtifactIdentity $identity,
    ) {}

    public function class(): ?ClassIdentity
    {
        return $this->identity instanceof ClassIdentity ? $this->identity : null;
    }

    public function fqcn(): ?string
    {
        return $this->class()?->fqcn();
    }

    public function path(): string
    {
        return $this->identity->path();
    }

    public function equals(self $other): bool
    {
        return $other->kind->id === $this->kind->id
            && $other->context->equals($this->context)
            && $other->identity->equals($this->identity);
    }

    public function describe(): string
    {
        return sprintf('%s %s [%s]', $this->kind->id, $this->fqcn() ?? $this->path(), $this->context->describe());
    }
}
