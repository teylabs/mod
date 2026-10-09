<?php

namespace Tey\Mod\Artifact;

use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Placement\PlacementContext;

/**
 * The outcome of placing a request: kind, placement, requested name and identity.
 *
 * `nested` holds the folders a nested name carried below the kind's own
 * folder ("Billing/Invoice" → ['Billing'], name 'Invoice'); empty otherwise.
 *
 * @api
 */
final readonly class ResolvedArtifact
{
    /** @api */
    public CompiledFileType $fileType;

    /** Create the exact class identity selected by a host callback. @api */
    public static function phpClass(string $fileType, string $namespace, string $basename, string $path, ?PlacementContext $context = null): self
    {
        return new self(ArtifactKind::phpClass($fileType), $context ?? PlacementContext::none(), $basename, new ClassIdentity($namespace, $basename, CompiledRoot::normalisePath($path)));
    }

    /** @api */
    public function namespace(): ?string
    {
        return $this->class()?->namespace;
    }

    /**
     * @param  list<string>  $nested
     *
     * @internal
     */
    public function __construct(
        /** @internal */
        public ArtifactKind $kind,
        /** @api */
        public PlacementContext $context,
        /** @api */
        public string $name,
        /** @internal */
        public ArtifactIdentity $identity,
        /**
         * @api
         *
         * @var list<string>
         */
        public array $nested = [],
    ) {
        $this->fileType = new CompiledFileType($kind);
    }

    /** @internal */
    public function class(): ?ClassIdentity
    {
        return $this->identity instanceof ClassIdentity ? $this->identity : null;
    }

    /** @api */
    public function fqcn(): ?string
    {
        return $this->class()?->fqcn();
    }

    /** @api */
    public function path(): string
    {
        return $this->identity->path();
    }

    /**
     * The name as it was requested: the nested folders and the stem, '/'-joined
     * ("Archived/Invoice"); for the namespace form replace '/' with '\\'.
     *
     * @api
     */
    public function nestedName(): string
    {
        return implode('/', [...$this->nested, $this->name]);
    }

    /** @api */
    public function equals(self $other): bool
    {
        return $other->kind->id === $this->kind->id
            && $other->context->equals($this->context)
            && $other->nested === $this->nested
            && $other->identity->equals($this->identity);
    }

    /** @api */
    public function describe(): string
    {
        return sprintf('%s %s [%s]', $this->kind->id, $this->fqcn() ?? $this->path(), $this->context->describe());
    }
}
