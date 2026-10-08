<?php

namespace Tey\Mod\Exceptions;

final class MissingDimension extends ModException
{
    /** The kind that needs the value, and the dimension it is missing. */
    public string $kindId = '';

    public string $dimension = '';

    public static function for(string $kindId, string $dimension): self
    {
        $exception = new self("Artifact kind [{$kindId}] requires a [{$dimension}] placement value; pass it with --in.");
        $exception->kindId = $kindId;
        $exception->dimension = $dimension;

        return $exception;
    }
}
