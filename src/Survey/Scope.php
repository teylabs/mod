<?php

namespace Tey\Mod\Survey;

use FilesystemIterator;
use SplFileInfo;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Support\Path;

/** @internal The survey's read boundary; excluded paths are never opened. */
final readonly class Scope
{
    public string $root;

    public function __construct(string $root)
    {
        $this->root = Path::normalize(realpath($root) ?: throw new ModException('The app root is unavailable.'));
    }

    public function excluded(string $path): bool
    {
        foreach (explode('/', strtolower(Path::normalize($path))) as $part) {
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

        return (string) file_get_contents($absolute);
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
            $this->resolve($path);
            // Even in-tree links are not traversed: no cycles or aliases into protected data.
            if ($entry->isLink()) {
                if ($entry->getFilename() === '.gitignore') {
                    throw new ModException('Survey refuses linked git ignore files.');
                }

                continue;
            }
            if ($entry->isDir()) {
                if (! file_exists($entry->getPathname().'/.git')) {
                    array_push($files, ...$this->files($path));
                }
            } elseif ($entry->isFile()) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
