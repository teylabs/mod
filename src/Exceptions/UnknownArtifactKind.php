<?php

namespace Tey\Mod\Exceptions;

final class UnknownArtifactKind extends ModException
{
    public static function id(string $kindId): self
    {
        return new self("Unknown artifact kind [{$kindId}]: the active preset does not declare it.");
    }
}
