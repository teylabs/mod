<?php

namespace Tey\Mod\Pages;

use InvalidArgumentException;
use Tey\Mod\Support\Path;

/** @internal Provider declarations are scoped to one application. */
final class PageRoots
{
    /** @var array<string, string> */
    private array $roots = [];

    public function register(string $namespace, string $directory): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Page namespaces must contain letters, numbers, underscores or hyphens.');
        }
        $directory = Path::normalize(\Symfony\Component\Filesystem\Path::canonicalize($directory));
        if (isset($this->roots[$namespace]) && $this->roots[$namespace] !== $directory) {
            throw new InvalidArgumentException('Conflicting page namespace ['.$namespace.'].');
        }
        $this->roots[$namespace] = $directory;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->roots;
    }
}
