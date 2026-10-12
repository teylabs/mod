<?php

namespace Tey\Mod\Discovery;

use Closure;
use DirectoryIterator;

/** @internal Reuses immutable inventories, never application registration state. */
final class InProcessInventory
{
    /** @internal Test-only hook for counting cold scans. */
    public ?Closure $onScan = null;

    /** @var array<string, array{fingerprint: string, directories: array<string, int|false>, inventory: Inventory}> */
    private array $inventories = [];

    public static function instance(): self
    {
        static $instance = new self;

        return $instance;
    }

    /**
     * @param  list<string>  $roots  absolute discovered roots, including related factory and policy roots
     * @param  Closure(): Inventory  $scan
     */
    public function remember(string $basePath, string $fingerprint, array $roots, Closure $scan): Inventory
    {
        $previous = $this->inventories[$basePath] ?? null;

        if ($previous !== null && $previous['fingerprint'] === $fingerprint
            && self::mtimes(array_keys($previous['directories'])) === $previous['directories']) {
            return $previous['inventory'];
        }

        // Record every directory, not just roots: a file added deep in a tree
        // changes only its parent's mtime. Missing roots are tracked too.
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
        $mtimes = self::mtimes(array_keys($directories));
        $inventory = $scan();
        $this->inventories[$basePath] = ['fingerprint' => $fingerprint, 'directories' => $mtimes, 'inventory' => $inventory];

        return $inventory;
    }

    public function flush(): void
    {
        $this->inventories = [];
    }

    /**
     * No tree walk or file reads on a hit. A directory added or removed changes
     * a recorded parent's mtime; a mismatch rebuilds the directory list.
     *
     * @param  list<string>  $directories
     * @return array<string, int|false>
     */
    private static function mtimes(array $directories): array
    {
        $mtimes = [];
        foreach ($directories as $directory) {
            clearstatcache(true, $directory);
            $mtimes[$directory] = is_dir($directory) && ! is_link($directory) ? filemtime($directory) : false;
        }

        return $mtimes;
    }
}
