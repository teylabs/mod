<?php

namespace Tey\Mod\Exceptions;

final class UnknownRelation extends ModException
{
    public static function id(string $relationId): self
    {
        return new self("Unknown relation [{$relationId}]: the active preset does not declare it.");
    }
}
