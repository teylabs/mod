<?php

namespace Tey\Mod\Maintenance;

use FilesystemIterator;
use RuntimeException;

/** Conservative no-follow inventory; filenames confer no deletion/adoption authority. @internal */
final class Orphans
{
    /** @return list<array{path: string, kind: string}> */
    public static function inspect(string $root, Artifact $target): array
    {
        SafeFilesystem::root($root);
        $found = [];
        $metadataAlias = false;
        foreach (self::entries($root) as $name) {
            if (strtolower($name) === '.mod-maintenance' && $name !== '.mod-maintenance') {
                $found[$name] = self::kind($root.'/'.$name);
                $metadataAlias = true;
            }
            if (str_starts_with(strtolower($name), '.mod-maintenance-stage-')) {
                $found[$name] = self::kind($root.'/'.$name);
            }
        }
        $metadata = $root.'/.mod-maintenance';
        if (! $metadataAlias && (file_exists($metadata) || is_link($metadata))) {
            $kind = self::kind($metadata);
            if ($kind !== 'directory') {
                $found['.mod-maintenance'] = $kind;
            } else {
                foreach (self::entries($metadata) as $name) {
                    $kind = self::kind($metadata.'/'.$name);
                    $known = in_array($name, ['owner.json', 'lock', 'installed.json'], true) || preg_match('/\Ajournal-[a-f0-9]{64}\.json\z/', $name);
                    if (! $known || $kind !== 'file') {
                        $found['.mod-maintenance/'.$name] = $kind;
                    }
                }
            }
        }
        $parents = [];
        foreach ($target->files as $path => $bytes) {
            $parent = dirname($path);
            $parents[$parent][] = basename($path).'.mod-maintenance-tmp-';
        }
        if (count($parents) > 256) {
            throw new RuntimeException('Maintenance diagnostics exceed the supported directory bound.');
        }
        foreach ($parents as $parent => $prefixes) {
            $directory = $parent === '.' ? $root : SafeFilesystem::path($root, $parent);
            if (! is_dir($directory)) {
                continue;
            }
            foreach (self::entries($directory) as $name) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with(strtolower($name), strtolower($prefix))) {
                        $relative = $parent === '.' ? $name : $parent.'/'.$name;
                        $found[$relative] = self::kind($directory.'/'.$name);
                    }
                }
            }
        }
        ksort($found);
        $result = [];
        foreach ($found as $path => $kind) {
            $result[] = ['path' => $path, 'kind' => $kind];
        }

        return $result;
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $names = [];
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (count($names) >= 4096) {
                throw new RuntimeException('Maintenance diagnostics exceed the supported entry bound.');
            }
            $names[] = $iterator->getFilename();
        }

        return $names;
    }

    private static function kind(string $path): string
    {
        $stat = lstat($path);
        if ($stat === false) {
            throw new RuntimeException('Maintenance candidate changed during diagnostics.');
        }

        return match ($stat['mode'] & 0170000) {
            0120000 => 'link',
            0040000 => 'directory',
            0100000 => $stat['nlink'] === 1 ? 'file' : 'hardlinked_file',
            default => 'other',
        };
    }
}
