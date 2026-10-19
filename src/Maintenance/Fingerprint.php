<?php

namespace Tey\Mod\Maintenance;

/** @internal */
final class Fingerprint
{
    public static function of(mixed $value): string
    {
        return 'sha256:'.hash('sha256', serialize(self::ordered($value)));
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::ordered(...), $value);
    }
}
