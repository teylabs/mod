<?php

namespace Tey\Mod\Pages;

use Tey\Mod\Support\FrontendPaths;

/** @internal Provider declarations are scoped to one application. */
final class PageRoots
{
    /** @var array<string, string> */
    private array $roots = [];

    /** @var array<string, list<string>|null> */
    private array $entries = [];

    private FrontendPaths $diagnostics;

    public function __construct()
    {
        $this->diagnostics = new FrontendPaths('');
    }

    /** @param list<string>|null $entries */
    public function register(string $namespace, string $directory, ?array $entries = null): void
    {
        if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $namespace) !== 1) {
            $this->diagnostics->skip('page_namespace_invalid', $directory, 'Page namespaces must contain letters, numbers, underscores or hyphens.');

            return;
        }
        if (isset($this->roots[$namespace]) && $this->roots[$namespace] !== $directory) {
            $this->diagnostics->skip('page_namespace_conflict', $directory, 'Conflicting page namespace ['.$namespace.'].');

            return;
        }
        try {
            $entries = PageEntries::validate($entries, $directory);
        } catch (\InvalidArgumentException $exception) {
            $this->diagnostics->skip('page_entries_invalid', $directory, $exception->getMessage());

            return;
        }
        $this->roots[$namespace] = $directory;
        $this->entries[$namespace] = $entries;
    }

    /** @return list<string>|null */
    public function entries(string $namespace): ?array
    {
        return $this->entries[$namespace] ?? null;
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
