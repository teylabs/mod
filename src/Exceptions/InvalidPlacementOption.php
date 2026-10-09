<?php

namespace Tey\Mod\Exceptions;

/** @api */
final class InvalidPlacementOption extends ModException
{
    public static function noDimensions(string $option): self
    {
        return new self("This layout takes no placement, so drop --in={$option}.");
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
        return new self(sprintf('The placement was given twice, as --in=%s and as %s. Use one of them.', $in, implode(' and as ', $options)));
    }

    /**
     * @param  list<string>  $dimensions  the layout's dimensions, in --in order
     */
    public static function skipped(string $given, string $missing, array $dimensions): self
    {
        return new self(sprintf(
            '--%s needs --%s too: values are read in the layout\'s order (%s).',
            $given,
            $missing,
            implode(', then ', $dimensions),
        ));
    }

    public static function malformedValue(string $option, string $value): self
    {
        return new self("Placement [{$option}] contains an invalid value [{$value}]; values must be identifiers.");
    }
}
