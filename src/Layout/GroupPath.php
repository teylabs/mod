<?php

namespace Tey\Mod\Layout;

use Illuminate\Support\Str;
use Symfony\Component\Filesystem\Filesystem;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Support\Path;

/** @internal resolves group anchors before the ordinary placement compiler. */
final class GroupPath
{
    /**
     * @param  array<string, FileType>  $types
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @param  list<string>  $nesting
     * @return array{array<string, FileType>, array<string, array{namespace: ?string, path: string}>, array<string, string>}
     */
    public static function resolve(string $name, ?string $path, array $types, array $roots, array $nesting): array
    {
        $old = [];
        $optional = [];
        $hasPlaceholders = false;
        $hasAnchors = false;
        foreach ($types as $type) {
            preg_match_all('/\{(\w+)([+?]*)\}|@(\w+)/', $type->toArray()['in'] ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $token = ($match[1] ?? '') !== '' ? $match[1] : ($match[3] ?? '');
                $old[$token] = $token;
                if (! str_starts_with($match[0], '@')) {
                    $hasPlaceholders = true;
                    $optional[$token] = ($optional[$token] ?? true) && str_contains($match[2] ?? '', '?');
                } else {
                    $hasAnchors = true;
                }
            }
        }
        if ($path === null && ! $hasPlaceholders) {
            $token = self::derivedToken($name);
            $root = reset($roots);
            $path = ($root === false ? 'app' : $root['path']).'/{'.$token.'}';
        }
        if ($path === null && $hasAnchors) {
            $path = self::infer($types, $roots);
        }
        if ($path === null) {
            return [self::nest($types, $nesting, array_values($old), $name), $roots, []];
        }
        $path = self::projectPath($path);
        preg_match_all('/\{(\w+)[+?]*\}/', $path, $matches);
        $tokens = $matches[1];
        if (count($tokens) > 1 && ! str_contains($path, '*')) {
            foreach ($types as $type) {
                preg_match_all('/@(\w+)/', $type->toArray()['in'] ?? '', $anchors);
                foreach ($anchors[1] as $anchor) {
                    $optional[$anchor] = false;
                }
            }
        }
        $rename = [];
        $remaining = array_values(array_diff($tokens, array_values($old)));
        foreach ($old as $token) {
            $rename[$token] = in_array($token, $tokens, true) ? $token : (array_shift($remaining) ?? $token);
        }
        $rewritten = [];
        foreach ($types as $id => $type) {
            $type = clone $type;
            $in = $type->toArray()['in'];
            if ($in === null) {
                $rewritten[$id] = $type;

                continue;
            }
            $in = (string) preg_replace_callback('/\{(\w+)([+?]*)\}|@(\w+)/', static function (array $match) use ($rename): string {
                return str_starts_with($match[0], '@')
                    ? '@'.($rename[$match[3] ?? ''] ?? ($match[3] ?? ''))
                    : '{'.($rename[$match[1]] ?? $match[1]).$match[2].'}';
            }, $in);
            if (! $hasPlaceholders && $tokens !== [] && ! str_contains($in, ':') && ! str_starts_with($in, '@')) {
                $in = '@'.end($tokens).($in === '' ? '' : '/'.$in);
            }
            if (preg_match('/^@(\w+)(?:\/(.*))?$/', $in, $anchor) === 1) {
                $token = $anchor[1];
                $index = array_search($token, $tokens, true);
                if ($index === false) {
                    throw new InvalidLayout($name, [], "Layout [{$name}] is invalid:\nAnchor @{$token} is not declared by path('{$path}').");
                }
                $parts = explode('/', $path);
                $group = [];
                foreach ($parts as $part) {
                    $group[] = $part;
                    if (preg_match('/^\{'.preg_quote($token, '/').'[+?]*\}$/', $part) === 1) {
                        break;
                    }
                }
                $target = implode('/', $group);
                $suffix = $anchor[2] ?? '';
                $target = str_contains($target, '*') ? str_replace('*', $suffix, $target) : Path::join($target, $suffix);
                foreach ($rename as $before => $after) {
                    if ($optional[$before] ?? false) {
                        $target = str_replace('{'.$after.'}', '{'.$after.'?}', $target);
                    }
                }
                $staticPrefix = substr($path, 0, strcspn($path, '{*'));
                [$rootName, $below, $roots] = self::rootFor($target, $roots, rtrim($staticPrefix, '/'));
                $type->withinRoot($rootName)->in($below);
            } else {
                $type->in($in);
            }
            $rewritten[$id] = $type;
        }

        return [self::nest($rewritten, $nesting, $tokens, $name), $roots, $rename];
    }

    public static function derivedToken(string $name): string
    {
        $token = Str::singular($name);
        if ($token === $name || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token) !== 1) {
            throw new InvalidLayout($name, [], "Layout [{$name}] is invalid:\n{$name} can't name a group; declare ->path('app/{group}').");
        }

        return $token;
    }

    /**
     * @param  array<string, FileType>  $types
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     */
    private static function infer(array $types, array $roots): ?string
    {
        $best = null;
        $depth = 0;
        foreach ($types as $type) {
            $definition = $type->toArray();
            $in = $definition['in'] ?? '';
            $root = $definition['root'] ?? array_key_first($roots);
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/', $in, $match) === 1) {
                $root = $match[1];
                $in = $match[2];
            }
            if ($root === null || ! isset($roots[$root])) {
                continue;
            }
            if (preg_match('/^(.*\{\w+[+?]*\})/', $in, $match) === 1 && substr_count($match[1], '{') > $depth) {
                $best = Path::join($roots[$root]['path'], $match[1]);
                $depth = substr_count($match[1], '{');
            }
        }

        return $best;
    }

    public static function projectPath(string $path): string
    {
        $path = Path::normalize($path);
        if ((new Filesystem)->isAbsolutePath($path) && function_exists('app') && app()->bound('path')) {
            return Path::relative(app()->basePath(), $path) ?? $path;
        }

        return $path;
    }

    /**
     * @param  array<string, array{namespace: ?string, path: string}>  $roots
     * @return array{string, string, array<string, array{namespace: ?string, path: string}>}
     */
    private static function rootFor(string $target, array $roots, string $groupRoot): array
    {
        $best = null;
        $below = '';
        foreach ($roots as $name => $root) {
            $relative = Path::relative(self::projectPath($root['path']), $target);
            if ($relative !== null && ($best === null || strlen($root['path']) > strlen($roots[$best]['path']))) {
                $best = $name;
                $below = $relative;
            }
        }
        if ($best !== null) {
            return [$best, $below, $roots];
        }
        $folder = $groupRoot;
        $key = 'group_'.substr(hash('sha256', $folder), 0, 12);
        $roots[$key] = ['namespace' => Str::studly(basename($folder)).'\\', 'path' => $folder];

        return [$key, ltrim(substr($target, strlen($folder)), '/'), $roots];
    }

    /**
     * @param  array<string, FileType>  $types
     * @param  list<string>  $nesting
     * @param  list<string>  $tokens
     * @return array<string, FileType>
     */
    private static function nest(array $types, array $nesting, array $tokens, string $name): array
    {
        foreach ($nesting as $dimension) {
            if ($dimension === '') {
                if (count($tokens) !== 1) {
                    throw new InvalidLayout($name, [], "Layout [{$name}] is invalid:\nallowsNesting() needs a dimension: ".implode(', ', $tokens).'.');
                }
                $dimension = $tokens[0];
            }
            if (! in_array($dimension, $tokens, true)) {
                throw new InvalidLayout($name, [], "Layout [{$name}] is invalid:\nallowsNesting('{$dimension}') names no group. Choose ".implode(', ', $tokens).'.');
            }
            foreach ($types as $id => $type) {
                $types[$id] = clone $type;
                $in = $type->toArray()['in'];
                if ($in !== null) {
                    $types[$id]->in((string) preg_replace('/\{'.preg_quote($dimension, '/').'\+?(\??)\}/', '{'.$dimension.'+$1}', $in));
                }
            }
        }

        return $types;
    }
}
