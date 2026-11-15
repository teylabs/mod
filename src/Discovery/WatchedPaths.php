<?php

namespace Tey\Mod\Discovery;

use DirectoryIterator;
use ReflectionClass;

/**
 * The paths a scan's result depends on, so a later boot can tell with stat()
 * calls alone whether the result still holds.
 *
 * Ownership is decided by path, so a directory's mtime (it changes when an
 * entry is added, removed or renamed) covers every file the scan only
 * listed. Eligibility reads class contents, so every class the scan loaded
 * is watched by file, with the files of its parents, interfaces and traits,
 * wherever they live. A class the scan needed but could not load makes the
 * result unresolved: the file that would fix it may appear anywhere.
 *
 * @internal used by Discovery for the automatic cache.
 */
final class WatchedPaths
{
    /** @var array<string, true> */
    private array $paths = [];

    private bool $unresolved = false;

    public function file(string $path): void
    {
        $this->paths[$path] = true;
    }

    /**
     * The loaded class's file, and the files of its parents, interfaces and traits.
     *
     * @param  class-string  $class
     */
    public function class(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $types = [...$reflection->getInterfaces()];

        for ($type = $reflection; $type !== false; $type = $type->getParentClass()) {
            $types[] = $type;
        }

        while (($type = array_pop($types)) !== null) {
            $file = $type->getFileName();

            if ($file !== false) {
                $this->paths[$file] = true;
            }

            array_push($types, ...array_values($type->getTraits()));
        }
    }

    /** A class the scan needed could not be loaded. */
    public function unresolved(): void
    {
        $this->unresolved = true;
    }

    /**
     * The roots and every directory below them. A missing root is watched too, so creating it counts.
     *
     * @param  list<string>  $roots  absolute
     */
    public function directories(array $roots): void
    {
        foreach (self::tree($roots) as $directory) {
            $this->paths[$directory] = true;
        }
    }

    /**
     * The roots and every directory below them, sorted; symlinks are not followed.
     *
     * @param  list<string>  $roots  absolute
     * @return list<string>
     */
    public static function tree(array $roots): array
    {
        $directories = [];

        while (($root = array_pop($roots)) !== null) {
            if (isset($directories[$root])) {
                continue;
            }
            $directories[$root] = true;
            clearstatcache(true, $root);
            if (! is_dir($root) || is_link($root)) {
                continue;
            }
            foreach (new DirectoryIterator($root) as $item) {
                if (! $item->isDot() && ! $item->isLink() && $item->isDir()) {
                    $roots[] = $item->getPathname();
                }
            }
        }
        ksort($directories);

        return array_keys($directories);
    }

    /**
     * The mtime and size of every watched path (false when it is missing), or
     * null when the result cannot be trusted: a class was unresolved, or a
     * path changed within a second of $since. Mtimes have one-second
     * resolution, so a later change in that second would look the same.
     *
     * @return array<string, array{int, int}|false>|null
     */
    public function signatures(int $since): ?array
    {
        if ($this->unresolved) {
            return null;
        }

        $signatures = self::stat(array_keys($this->paths));

        foreach ($signatures as $signature) {
            if ($signature !== false && $signature[0] >= $since - 1) {
                return null;
            }
        }

        return $signatures;
    }

    /**
     * Whether every recorded path still has its recorded signature. Stats only; no walk, no reads.
     *
     * @param  array<array-key, mixed>  $signatures
     */
    public static function unchanged(array $signatures): bool
    {
        $paths = array_keys($signatures);

        return $paths !== [] && array_filter($paths, is_string(...)) === $paths && self::stat($paths) === $signatures;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{int, int}|false>
     */
    private static function stat(array $paths): array
    {
        clearstatcache();
        $signatures = [];

        foreach ($paths as $path) {
            $stat = @stat($path);
            $signatures[$path] = $stat === false ? false : [$stat['mtime'], $stat['size']];
        }

        return $signatures;
    }
}
