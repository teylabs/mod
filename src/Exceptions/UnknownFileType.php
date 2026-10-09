<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class UnknownFileType extends ModException
{
    public static function id(string $kindId): self
    {
        return new self("The active layout has no [{$kindId}] file type.");
    }
}
