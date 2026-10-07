<?php

namespace Tey\Mod\Artifact;

final readonly class ClassIdentity implements ArtifactIdentity
{
    public function __construct(
        public string $namespace,
        public string $basename,
        private string $path,
    ) {}

    public function fqcn(): string
    {
        return $this->namespace === '' ? $this->basename : $this->namespace.'\\'.$this->basename;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function equals(ArtifactIdentity $other): bool
    {
        return $other instanceof self && $other->fqcn() === $this->fqcn() && $other->path === $this->path;
    }
}
