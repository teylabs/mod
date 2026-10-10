<?php

namespace Tey\Mod\Rename\Git;

/** @internal Windows exposes a read-only attribute; Unix exposes exact mode bits. */
final class FileMode
{
    public static function matches(int $current, int $expected, string $platform = PHP_OS_FAMILY): bool
    {
        return $platform === 'Windows'
            ? (($current & 0222) !== 0) === (($expected & 0222) !== 0)
            : $current === $expected;
    }
}
