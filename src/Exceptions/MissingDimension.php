<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class MissingDimension extends ModException
{
    /** The kind that needs the value, and the dimension it is missing. */
    public string $kindId = '';

    public string $dimension = '';

    /** @internal */
    public function describe(string $message): self
    {
        $copy = new self($message);
        $copy->kindId = $this->kindId;
        $copy->dimension = $this->dimension;

        return $copy;
    }

    public static function for(string $kindId, string $dimension): self
    {
        $exception = new self("File type [{$kindId}] needs a [{$dimension}]; pass it with --in or the Group:Name short form.");
        $exception->kindId = $kindId;
        $exception->dimension = $dimension;

        return $exception;
    }
}
