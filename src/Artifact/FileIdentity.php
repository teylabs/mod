<?php

namespace Tey\Mod\Artifact;

final readonly class FileIdentity implements ArtifactIdentity
{
    public function __construct(private string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function basename(): string
    {
        return basename($this->path);
    }

    public function equals(ArtifactIdentity $other): bool
    {
        return $other instanceof self && $other->path === $this->path;
    }
}
