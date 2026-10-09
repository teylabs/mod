<?php

namespace Tey\Mod\Generation\PlainFile;

use Illuminate\Support\Str;

/** @internal Extension conventions shared by placement and inventory. */
final class Casing
{
    public static function forExtension(string $extension): string
    {
        return match ($extension) {
            '.vue' => 'studly',
            '.tsx', '.jsx', '.blade.php', '.md', '.css' => 'kebab',
            default => 'as-given',
        };
    }

    public static function name(string $name, string $case): string
    {
        return match ($case) {
            'studly' => Str::studly($name),
            'kebab' => strtolower((string) preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/', '/[_\s]+/'], ['$1-$2', '$1-$2', '-'], $name)),
            'snake' => Str::snake($name),
            'camel' => Str::camel($name),
            default => $name,
        };
    }

    public static function folder(string $name, string $case): string
    {
        return $case === 'studly' ? $name : self::name($name, $case);
    }
}
