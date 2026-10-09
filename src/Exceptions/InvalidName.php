<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class InvalidName extends ModException
{
    /**
     * @param  list<string>  $dimensions
     */
    public static function nested(string $name, array $dimensions): self
    {
        $hint = $dimensions === []
            ? 'Nested names are not supported.'
            : 'Nested names are not supported; place it with --in='.implode('/', array_map(
                static fn (string $dimension): string => '<'.$dimension.'>',
                $dimensions,
            )).' instead.';

        return new self("Invalid name [{$name}]. {$hint}");
    }

    public static function malformed(string $name, string $expected): self
    {
        return new self("Invalid name [{$name}]: expected {$expected}.");
    }

    public static function missingAttribute(string $kindId, string $attribute, string $expected): self
    {
        return new self("File type [{$kindId}] needs a [{$attribute}] attribute ({$expected}).");
    }
}
