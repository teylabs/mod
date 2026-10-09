<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class UnknownRelation extends ModException
{
    public static function id(string $relationId): self
    {
        return new self("Unknown relation [{$relationId}]: the active layout does not declare it.");
    }
}
