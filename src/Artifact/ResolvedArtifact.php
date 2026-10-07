<?php

namespace Tey\Mod\Artifact;

use Tey\Mod\Placement\PlacementContext;

/**
 * The outcome of placing a request: kind, placement, requested name and identity.
 *
 * `nested` holds the folders a nested name carried below the kind's own
 * folder ("Billing/Invoice" → ['Billing'], name 'Invoice'); empty otherwise.
 */
final readonly class ResolvedArtifact
{
    /**
     * @param  list<string>  $nested
     */
    public function __construct(
        public ArtifactKind $kind,
        public PlacementContext $context,
        public string $name,
        public ArtifactIdentity $identity,
        public array $nested = [],
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

    /**
     * The name as it was requested: the nested folders and the stem, '/'-joined
     * ("Archived/Invoice"); for the namespace form replace '/' with '\\'.
     */
    public function nestedName(): string
    {
        return implode('/', [...$this->nested, $this->name]);
    }

    public function equals(self $other): bool
    {
        return $other->kind->id === $this->kind->id
            && $other->context->equals($this->context)
            && $other->nested === $this->nested
            && $other->identity->equals($this->identity);
    }

    public function describe(): string
    {
        return sprintf('%s %s [%s]', $this->kind->id, $this->fqcn() ?? $this->path(), $this->context->describe());
    }
}
