<?php

namespace Tey\Mod\Discovery;

use Tey\Mod\Support\ComposerJson;
use Tey\Mod\Support\Path;

/** @internal Locate group folders from the declared path, without compiling templates. */
final readonly class GroupDirectories
{
    public function __construct(private string $basePath, private ?string $pattern) {}

    /** @return array<string, array{path: string, namespace: ?string}> */
    public function all(): array
    {
        if ($this->pattern === null || ! str_contains($this->pattern, '{') || str_contains($this->pattern, '*')) {
            return [];
        }
        $groups = [];
        // Begin at the literal prefix so an absolute path retains its root.
        $pattern = Path::normalize($this->pattern);
        $token = strpos($pattern, '{');
        if ($token === false) {
            return [];
        }
        $prefix = substr($pattern, 0, $token);
        $start = strrpos($prefix, '/');
        $root = $start === false ? '' : substr($pattern, 0, $start + 1);
        $parts = explode('/', substr($pattern, strlen($root)));
        $this->walk($parts, 0, $root, [], $groups);
        ksort($groups);

        return $groups;
    }

    /** @param list<string> $parts
     * @param  list<string>  $values
     * @param  array<string, array{path: string, namespace: ?string}>  $groups
     */
    private function walk(array $parts, int $index, string $path, array $values, array &$groups, int $depth = 0): void
    {
        if ($depth > 20) {
            return;
        }
        $part = $parts[$index] ?? null;
        if ($part === null) {
            if ($values !== [] && is_dir(Path::resolve($this->basePath, $path))) {
                $groups[implode('/', $values)] = ['path' => $path, 'namespace' => $this->namespace($path)];
            }

            return;
        }
        if (preg_match('/^\{\w+([+?]*)\}$/', $part, $match) !== 1) {
            $this->walk($parts, $index + 1, Path::join($path, $part), $values, $groups, $depth + 1);

            return;
        }
        $directory = Path::resolve($this->basePath, $path);
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $folder) {
            if ($folder === '.' || $folder === '..' || is_link(Path::join($directory, $folder)) || ! is_dir(Path::join($directory, $folder))) {
                continue;
            }
            $child = Path::join($path, $folder);
            $this->walk($parts, $index + 1, $child, [...$values, $folder], $groups, $depth + 1);
            if (str_contains($match[1], '+')) {
                $next = $values;
                $next[] = $folder;
                $this->walk($parts, $index, $child, [implode('/', $next)], $groups, $depth + 1);
            }
        }
    }

    private function namespace(string $path): ?string
    {
        $manifest = Path::join($this->basePath, 'composer.json');
        if (! is_file($manifest)) {
            return null;
        }
        $best = null;
        $length = -1;
        foreach ((new ComposerJson($manifest))->mappings() as $namespace => $roots) {
            foreach ((array) $roots as $root) {
                $relative = Path::relative(Path::resolve($this->basePath, $root), Path::resolve($this->basePath, $path));
                if ($relative !== null && strlen($root) > $length) {
                    $best = rtrim($namespace, '\\').($relative === '' ? '' : '\\'.str_replace('/', '\\', $relative));
                    $length = strlen($root);
                }
            }
        }

        return $best;
    }
}
