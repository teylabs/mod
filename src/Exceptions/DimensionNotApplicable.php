<?php

namespace Tey\Mod\Exceptions;

final class DimensionNotApplicable extends ModException
{
    /** The kind that was given the value, and the dimension it does not use. */
    public string $kindId = '';

    public string $dimension = '';

    public static function for(string $kindId, string $dimension): self
    {
        $exception = new self("File type [{$kindId}] does not use a [{$dimension}] in this layout.");
        $exception->kindId = $kindId;
        $exception->dimension = $dimension;

        return $exception;
    }
}
