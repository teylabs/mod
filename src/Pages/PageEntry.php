<?php

namespace Tey\Mod\Pages;

use InvalidArgumentException;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/** @internal Map the current page to a project-relative Vite manifest key. */
final readonly class PageEntry
{
    public function __construct(private PageDirectories $directories, private string $basePath) {}

    public function path(string $page, string $extension): string
    {
        if (! in_array($extension, ['vue', 'jsx', 'tsx', 'js', 'ts', 'svelte'], true)) {
            throw new InvalidArgumentException('Use the page extension from the Inertia stack.');
        }
        $parts = explode('::', $page);
        $name = array_pop($parts);
        if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $name) !== 1 || count($parts) > 1) {
            throw new InvalidArgumentException('Use a page name without traversal or an extension.');
        }
        if ($parts === []) {
            return (new Stack($this->basePath))->pagesPath().'/'.$name.'.'.$extension;
        }
        $directory = $this->directories->namespaces()[$parts[0]] ?? null;
        if ($directory === null || ($relative = Path::relative($this->basePath, $directory)) === null) {
            throw new InvalidArgumentException('Unknown page namespace ['.$parts[0].']. Check the layout frontend paths.');
        }

        return $relative.'/'.$name.'.'.$extension;
    }
}
