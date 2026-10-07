<?php

namespace Tey\Mod\Artifact;

/**
 * Identifier rules shared by names, namespace segments and placement values.
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
}
