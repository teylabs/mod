<?php

namespace Tey\Mod\Assets;

use InvalidArgumentException;

/**
 * @internal Static globs and executable file templates are separate declarations.
 *
 * @phpstan-type Assets array{owner: string, files: list<string>, entries: list<string>}
 */
final class AssetDeclarations
{
    /** @return list<Assets> */
    public static function validate(mixed $input): array
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Assets must be a collection of owners.');
        }
        $records = [];
        foreach ($input as $record) {
            if (! is_array($record) || ! is_string($record['owner'] ?? null)) {
                throw new InvalidArgumentException('Assets need an owner.');
            }
            $files = self::paths($record['files'] ?? [], true);
            $entries = self::paths($record['entries'] ?? [], false);
            $records[] = ['owner' => $record['owner'], 'files' => $files, 'entries' => $entries];
        }

        return $records;
    }

    /** @return list<string> */
    private static function paths(mixed $input, bool $globs): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            throw new InvalidArgumentException('Asset paths must be a list.');
        }
        $paths = [];
        foreach ($input as $path) {
            if (! is_string($path) || $path === '' || preg_match('~^/|^[A-Za-z]:|(?:^|/)\.\.(?:/|$)|[\\\\\x00-\x1f\x27"`\[\]]~', $path)) {
                throw new InvalidArgumentException('Asset paths must stay inside the application.');
            }
            $literal = (string) preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\??\}/', '', $path);
            if (str_contains($literal, '{') || str_contains($literal, '}') || str_contains($literal, '?') || (! $globs && str_contains($literal, '*'))) {
                throw new InvalidArgumentException('Use literal executable entries and static globs with * or **.');
            }
            if (! $globs && ! preg_match('/\.(css|js|ts|jsx|tsx)$/D', $path)) {
                throw new InvalidArgumentException('Executable asset entries must be CSS or JavaScript files.');
            }
            if ($globs && preg_match('/\.(css|js|ts|jsx|tsx)$/D', $path)) {
                throw new InvalidArgumentException('Styles and scripts must be imported or declared as entries, not raw static files.');
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }
}
