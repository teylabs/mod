<?php

namespace Tey\Mod\Artifact;

/**
 * Where an artifact lives. A class has a namespace and a path; a file only a path.
 *
 * @internal
 */
interface ArtifactIdentity
{
    /** Application-relative path with forward slashes. */
    public function path(): string;

    public function equals(ArtifactIdentity $other): bool;
}
