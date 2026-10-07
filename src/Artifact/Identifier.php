<?php

namespace Tey\Mod\Artifact;

/**
 * Identifier rules shared by names, namespace segments and placement values.
 *
 * @internal shared validation rules.
 */
final class Identifier
{
    public const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public const FILE_PATTERN = '/^[a-z0-9_][a-z0-9_.-]*$/';

    public static function isClassSegment(string $value): bool
    {
        return preg_match(self::CLASS_PATTERN, $value) === 1;
    }

    public static function isFileStem(string $value): bool
    {
        return preg_match(self::FILE_PATTERN, $value) === 1;
    }

    public static function isNested(string $value): bool
    {
        return str_contains($value, '\\') || str_contains($value, '/');
    }

    /**
     * Split a nested name ("Billing/Invoice", "Billing\Invoice") into its folder segments and basename.
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function splitNested(string $value): array
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $value)), static fn (string $part): bool => $part !== ''));
        $basename = (string) array_pop($parts);

        return [$parts, $basename];
    }

    /**
     * Whether every folder of a '/'-joined chain is a class segment (and the chain is not empty).
     */
    public static function isSegmentChain(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (explode('/', $value) as $part) {
            if (! self::isClassSegment($part)) {
                return false;
            }
        }

        return true;
    }
}
