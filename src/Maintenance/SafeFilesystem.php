<?php

namespace Tey\Mod\Maintenance;

use RuntimeException;

/** @internal */
final class SafeFilesystem
{
    public static function root(string $root): void
    {
        clearstatcache();
        if (realpath($root) !== $root || ! is_dir($root)) {
            throw new RuntimeException('Checkout root must be an existing canonical directory.');
        }
        $cursor = '';
        foreach (explode('/', substr($root, 1)) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Linked checkout root or parent is unsupported.');
            }
        }
    }

    public static function read(string $root, string $relative): ?string
    {
        Artifact::validateFiles([$relative => '']);
        $path = self::path($root, $relative);
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path)) {
            throw new RuntimeException('Expected a regular file at declared path.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read declared file.');
        }

        return $bytes;
    }

    public static function path(string $root, string $relative): string
    {
        $cursor = $root;
        $segments = explode('/', $relative);
        foreach ($segments as $index => $segment) {
            if (is_dir($cursor)) {
                $entries = scandir($cursor);
                if ($entries === false) {
                    throw new RuntimeException('Unable to inspect declared parent.');
                }
                $aliases = array_values(array_filter($entries, static fn (string $entry): bool => strtolower($entry) === strtolower($segment)));
                if ($aliases !== [] && $aliases !== [$segment]) {
                    throw new RuntimeException('Case alias or collision at declared path.');
                }
            }
            $cursor .= '/'.$segment;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new RuntimeException('Linked declared path or parent is unsupported.');
            }
            if ($index < count($segments) - 1 && file_exists($cursor) && ! is_dir($cursor)) {
                throw new RuntimeException('Declared file parent is not a directory.');
            }
        }

        return $cursor;
    }

    public static function metadata(string $root): void
    {
        $path = self::path($root, '.mod-maintenance');
        if (! file_exists($path)) {
            return;
        }
        self::metadataDirectory($path);
    }

    private static function metadataDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            throw new RuntimeException('Maintenance metadata must be an unlinked directory.');
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('Unable to inspect maintenance metadata.');
        }
        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $folded = strtolower($entry);
            if (in_array($folded, $names, true)) {
                throw new RuntimeException('Case collision in maintenance metadata.');
            }
            $names[] = $folded;
            $child = $path.'/'.$entry;
            if (is_link($child)) {
                throw new RuntimeException('Linked maintenance metadata is unsupported.');
            }
            if (is_dir($child)) {
                self::metadataDirectory($child);
            } elseif (! is_file($child)) {
                throw new RuntimeException('Unsupported maintenance metadata entry.');
            }
        }
    }
}
