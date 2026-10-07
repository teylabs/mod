<?php

namespace Tey\Mod\Exceptions;

final class InvalidPlacementOption extends ModException
{
    public static function noDimensions(string $option): self
    {
        return new self("Placement [{$option}] was given but this layout declares no placement dimensions.");
    }

    /**
     * @param  list<string>  $dimensions
     */
    public static function tooManyValues(string $option, array $dimensions): self
    {
        return new self(sprintf(
            'Placement [%s] has too many values; this layout accepts at most %d (%s).',
            $option,
            count($dimensions),
            implode('/', $dimensions),
        ));
    }

    public static function malformedValue(string $option, string $value): self
    {
        return new self("Placement [{$option}] contains an invalid value [{$value}]; values must be identifiers.");
    }
}
