<?php

namespace Tey\Mod\Exceptions;

final class DimensionNotApplicable extends ModException
{
    /** The kind that was given the value, and the dimension it does not use. */
    public string $kindId = '';

    public string $dimension = '';

    public static function for(string $kindId, string $dimension): self
    {
        $exception = new self("Artifact kind [{$kindId}] does not take a [{$dimension}] placement value in this layout.");
        $exception->kindId = $kindId;
        $exception->dimension = $dimension;

        return $exception;
    }
}
