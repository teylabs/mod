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

    /**
     * @param  list<string>  $options  the dimension options given, e.g. ["--module=Billing"]
     */
    public static function oneOf(string $in, array $options): self
    {
        return new self(sprintf('Placement was given as --in=%s and as %s; use one of them.', $in, implode(' ', $options)));
    }

    /**
     * @param  list<string>  $dimensions  the layout's dimensions, in --in order
     */
    public static function skipped(string $given, string $missing, array $dimensions): self
    {
        return new self(sprintf(
            'Placement option --%s needs --%s as well: placement values are read in order (%s).',
            $given,
            $missing,
            implode('/', $dimensions),
        ));
    }

    public static function malformedValue(string $option, string $value): self
    {
        return new self("Placement [{$option}] contains an invalid value [{$value}]; values must be identifiers.");
    }
}
