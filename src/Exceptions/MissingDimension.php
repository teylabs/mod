<?php

namespace Tey\Mod\Exceptions;

final class MissingDimension extends ModException
{
    public static function for(string $kindId, string $dimension): self
    {
        return new self("Artifact kind [{$kindId}] requires a [{$dimension}] placement value; pass it with --in.");
    }
}
