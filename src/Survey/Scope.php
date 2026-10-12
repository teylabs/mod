<?php

namespace Tey\Mod\Survey;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use SplFileInfo;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Support\Path;

/** @internal The survey's read boundary; excluded paths are never opened. */
final class Scope
{
    public const MAX_SOURCE_BYTES = 1048576;

    public readonly string $root;

    /** @var list<array{path: string, reason: string}> */
    public array $skipped = [];

    public function __construct(string $root)
    {
        $this->root = Path::normalize(realpath($root) ?: throw new ModException('The app root is unavailable.'));
    }

    public function excluded(string $path): bool
    {
        $normalized = strtolower(Path::normalize($path));
        foreach (['bootstrap/cache', 'public/build', 'public/hot', 'public/storage'] as $excluded) {
            if (Path::relative($excluded, $normalized) !== null) {
                return true;
            }
        }
        foreach (explode('/', $normalized) as $part) {
            if (str_starts_with($part, '.env') || in_array($part, ['storage', 'logs', 'node_modules', '.git'], true)
                || preg_match('/\.(?:key|pem|log|sqlite\d*|db|sql)(?:[.-].*)?$/D', $part) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Validate lexically before resolving links, then validate the resolved target. */
    public function resolve(string $path): string
    {
        $absolute = Path::resolve($this->root, $path);
        if (Path::relative($this->root, $absolute) === null) {
            throw new ModException('Survey paths must stay inside base_path(); outside paths and traversal are refused.');
        }
        $real = realpath($absolute);
        if ($real !== false && Path::relative($this->root, $real) === null) {
            throw new ModException('Survey symlinks must stay inside base_path(); an outside target was refused.');
        }

        return $real === false ? $absolute : Path::normalize($real);
    }

    public function read(string $path): string
    {
        $absolute = $this->resolve($path);
        if ($this->excluded($path) || $this->excluded((string) Path::relative($this->root, $absolute))) {
            throw new ModException('Survey refuses protected files.');
        }

        if (filesize($absolute) > self::MAX_SOURCE_BYTES) {
            throw new ModException('Survey refuses files larger than 1 MiB.');
        }

        return (string) file_get_contents($absolute, false, null, 0, self::MAX_SOURCE_BYTES);
    }

    /** Classify from already loaded Composer maps, without invoking an autoloader. */
    public function vendorPath(string $class): ?string
    {
        foreach (spl_autoload_functions() as $autoload) {
            if (! is_array($autoload) || ! $autoload[0] instanceof ClassLoader) {
                continue;
            }
            $loader = $autoload[0];
            $candidates = isset($loader->getClassMap()[$class]) ? [$loader->getClassMap()[$class]] : [];
            foreach ($loader->getPrefixesPsr4() as $prefix => $roots) {
                if (str_starts_with($class, $prefix)) {
                    foreach ($roots as $root) {
                        $candidates[] = $root.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                    }
                }
            }
            foreach ($candidates as $candidate) {
                $relative = Path::relative($this->root, $candidate);
                if ($relative === null || ! str_starts_with($relative, 'vendor/') || $this->excluded($relative)) {
                    continue;
                }
                $real = $this->resolve($relative);
                $relative = Path::relative($this->root, $real);
                if ($relative !== null && str_starts_with($relative, 'vendor/') && ! $this->excluded($relative) && is_file($real)) {
                    return $relative;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public function files(string $directory = ''): array
    {
        $files = [];
        /** @var SplFileInfo $entry */
        foreach (new FilesystemIterator($this->resolve($directory), FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = ltrim($directory.'/'.$entry->getFilename(), '/');
            if ($this->excluded($path) || $path === 'vendor') {
                continue;
            }
            // Even in-tree links are not traversed: no cycles or aliases into protected data.
            if ($entry->isLink()) {
                $this->skipped[] = ['path' => $path, 'reason' => 'symlink'];

                continue;
            }
            $this->resolve($path);
            if ($entry->isDir()) {
                if (! file_exists($entry->getPathname().'/.git')) {
                    array_push($files, ...$this->files($path));
                }
            } elseif ($entry->isFile()) {
                if ($entry->getSize() > self::MAX_SOURCE_BYTES) {
                    $this->skipped[] = ['path' => $path, 'reason' => 'size-limit'];
                } else {
                    $files[] = $path;
                }
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
