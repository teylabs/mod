<?php

namespace Tey\Mod\Exceptions;

final class DimensionNotApplicable extends ModException
{
    public static function for(string $kindId, string $dimension): self
    {
        return new self("Artifact kind [{$kindId}] does not take a [{$dimension}] placement value in this layout.");
    }
}
