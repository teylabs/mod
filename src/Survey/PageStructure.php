<?php

namespace Tey\Mod\Survey;

use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Pages\PageEntries;

/**
 * @internal Page evidence from safe scan paths, without directory discovery or loaders.
 *
 * @phpstan-import-type Edge from PhpStructure
 *
 * @phpstan-type Page array{root: string, relative: string, entries: list<string>|null, name: ?string, entry: bool}
 */
final class PageStructure
{
    /** @param list<string> $files
     * @return array<string, Page>
     */
    public function read(array $files, CompiledLayout $layout): array
    {
        $pages = [];
        foreach ([$layout->frontend(), ...$layout->frontendOwners()] as $owner) {
            if ($owner['pages'] === null) {
                continue;
            }
            $regex = '';
            foreach (explode('/', $owner['pages']) as $segment) {
                if (preg_match('/^\{(\w+)(\?)?\}$/D', $segment, $token) !== 1) {
                    $regex .= '/'.preg_quote($segment, '~');

                    continue;
                }
                $multi = false;
                foreach ($layout->dimensions() as $dimension) {
                    if ($dimension->name === $token[1]) {
                        $multi = $dimension->multi;
                    }
                }
                $capture = '/(?P<'.$token[1].'>'.($multi ? '.+' : '[^/]+').')';
                $regex .= isset($token[2]) ? '(?:'.$capture.')?' : $capture;
            }
            foreach ($files as $path) {
                if (preg_match('~^'.ltrim($regex, '/').'/(?P<relative>.+)$~D', $path, $match) !== 1) {
                    continue;
                }
                $relative = $match['relative'];
                $root = substr($path, 0, -strlen($relative) - 1);
                if (isset($pages[$path]) && strlen($pages[$path]['root']) > strlen($root)) {
                    continue;
                }
                $name = null;
                if (in_array(pathinfo($path, PATHINFO_EXTENSION), ['vue', 'tsx', 'jsx', 'svelte'], true) && $owner['page_name'] !== null) {
                    $name = (string) preg_replace_callback('/\{(\w+)\??\}/', static fn (array $token): string => $token[1] === 'path'
                        ? substr($relative, 0, -(strlen(pathinfo($path, PATHINFO_EXTENSION)) + 1)) : ($match[$token[1]] ?? ''), $owner['page_name']);
                }
                $entries = $owner['page_entries'] ?? null;
                $pages[$path] = ['root' => $root, 'relative' => $relative, 'entries' => $entries,
                    'name' => $name, 'entry' => $name !== null && PageEntries::matches($path, $entries)];
            }
        }

        return $pages;
    }

    /** @param array<string, Page> $pages
     * @param  list<Edge>  $edges
     * @return array{page_entries: list<array{root: string, source: string, stems: list<string>}>, page_patterns: list<array{root: string, pattern: string, files: list<string>}>}
     */
    public function survey(array $pages, array $edges): array
    {
        $targets = array_column($edges, 'to');
        $entries = [];
        $shapes = [];
        $folders = [];
        foreach ($pages as $path => $page) {
            $root = $page['root'];
            $entries[$root] ??= ['root' => $root, 'source' => $page['entries'] === null ? 'inferred' : 'declared', 'stems' => $page['entries'] ?? []];
            if ($page['entries'] === null && $page['name'] !== null && in_array('page:'.$page['name'], $targets, true)) {
                $entries[$root]['stems'][] = pathinfo($path, PATHINFO_FILENAME);
            }
            $parts = explode('/', $page['relative']);
            if (count($parts) < 2) {
                continue;
            }
            $shapes[$root][count($parts).'|'.basename($path)][$path] = $parts;
            if (count($parts) > 2) {
                $folders[$root][$parts[count($parts) - 2]][$path] = $parts;
            }
        }
        $patterns = [];
        foreach ($shapes as $root => $groups) {
            foreach ($groups as $paths) {
                if (count(array_unique(array_column($paths, 0))) < 2) {
                    continue;
                }
                $parts = reset($paths);
                $parts[0] = '{name}';
                for ($i = 1; $i < count($parts) - 1; $i++) {
                    if (count(array_unique(array_column($paths, $i))) > 1) {
                        $parts[$i] = $i === 1 ? '{action}' : '*';
                    }
                }
                $patterns[] = ['root' => $root, 'pattern' => implode('/', $parts), 'files' => array_keys($paths)];
            }
        }
        foreach ($folders as $root => $groups) {
            foreach ($groups as $folder => $paths) {
                if (count(array_unique(array_column($paths, 0))) > 1 && count(array_unique(array_map('basename', array_keys($paths)))) > 1) {
                    $patterns[] = ['root' => $root, 'pattern' => '{name}/**/'.$folder.'/*', 'files' => array_keys($paths)];
                }
            }
        }

        return ['page_entries' => array_values($entries), 'page_patterns' => $patterns];
    }
}
