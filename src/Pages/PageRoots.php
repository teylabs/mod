<?php

namespace Tey\Mod\Pages;

use Tey\Mod\Support\FrontendPaths;

/** @internal Provider declarations are scoped to one application. */
final class PageRoots
{
    /** @var array<string, string> */
    private array $roots = [];

    private FrontendPaths $diagnostics;

    public function __construct()
    {
        $this->diagnostics = new FrontendPaths('');
    }

    public function register(string $namespace, string $directory): void
    {
        if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $namespace) !== 1) {
            $this->diagnostics->skip('page_namespace_invalid', $directory, 'Page namespaces must contain letters, numbers, underscores or hyphens.');

            return;
        }
        if (isset($this->roots[$namespace]) && $this->roots[$namespace] !== $directory) {
            $this->diagnostics->skip('page_namespace_conflict', $directory, 'Conflicting page namespace ['.$namespace.'].');

            return;
        }
        $this->roots[$namespace] = $directory;
    }

    /** @return list<array{code: string, path: string, message: string}> */
    public function notices(): array
    {
        return $this->diagnostics->notices();
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->roots;
    }
}
