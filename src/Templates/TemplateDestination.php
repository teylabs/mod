<?php

namespace Tey\Mod\Templates;

use Illuminate\Support\Str;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\GroupPath;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;

/** @internal Layout-aware path defaults and previews; slot-like source folders remain literal. */
final readonly class TemplateDestination
{
    public function __construct(private string $basePath, private Layout $layout, private CompiledLayout $compiled) {}

    public function bare(string $name): string
    {
        $token = $this->compiled->dimensionNames()[0] ?? null;

        return ($token === null ? '' : '@'.$token.'/').Str::plural(Str::studly($name)).'/'.$name;
    }

    public function parse(string $path): ParsedTemplate
    {
        $chain = $this->layout->toArray();
        [$types, $roots] = GroupPath::resolve($this->layout->name, $chain['path'], $chain['kinds'], $chain['roots'], $chain['nesting']);
        foreach ($this->compiled->roots() as $key => $root) {
            $roots[$key] = ['namespace' => $root->namespace, 'path' => $root->path];
        }

        return (new PathParser)->resolve($path, $this->layout->name, $chain['path'], $types, $roots);
    }

    public function canonical(string $path, ParsedTemplate $parsed): string
    {
        // Remove a redundant literal prefix only when it resolves to precisely the same destination.
        if (preg_match('#^.+/(@[^/]+/.*)$#', $path, $match) === 1) {
            try {
                $short = $this->parse($match[1]);
                if ($short->root === $parsed->root && $short->in === $parsed->in) {
                    return $match[1];
                }
            } catch (InvalidTemplate) {
                // The prefix is needed, for example for a second root.
            }
        }

        return $path;
    }

    public function suggest(string $file, string $name): string
    {
        $folder = Path::relative($this->basePath, dirname($file)) ?? Path::normalize(dirname($file));
        $declared = $this->layout->toArray()['path'];
        if ($declared !== null && str_contains($declared, '*')) {
            $pattern = '';
            $wildcards = [];
            $token = null;
            foreach (explode('/', GroupPath::projectPath($declared)) as $i => $segment) {
                if ($segment === '*') {
                    $key = 'literal'.$i;
                    $pattern .= '/(?P<'.$key.'>[^/]+)';
                    $wildcards[] = $key;
                } elseif (preg_match('/^\{(\w+)[+?]*\}$/', $segment, $match) === 1) {
                    $token = $match[1];
                    $pattern .= '/[^/]+';
                } else {
                    $pattern .= '/'.preg_quote($segment, '#');
                }
            }
            if ($token !== null && preg_match('#^'.ltrim($pattern, '/').'(?P<rest>/.*)?$#', $folder, $match) === 1) {
                $literals = array_map(static fn (string $key): string => $match[$key], $wildcards);

                return Path::join('@'.$token, ...[...$literals, $match['rest'] ?? '', Str::kebab($name)]);
            }
        }
        $best = null;
        foreach ($this->compiled->rules() as $rule) {
            if (! $rule instanceof TemplateRule) {
                continue;
            }
            $relative = Path::relative($rule->root()->path, $folder);
            if ($relative === null || $relative === '') {
                continue;
            }
            $parts = explode('/', $relative);
            $matched = [];
            $lastGroup = null;
            $lastToken = null;
            $position = 0;
            $segments = $rule->segments();
            foreach ($segments as $i => $segment) {
                if (! isset($parts[$position]) || ($segment->literal !== null && $segment->literal !== $parts[$position])) {
                    break;
                }
                $end = $position + 1;
                if ($segment->multi && isset($segments[$i + 1]) && $segments[$i + 1]->literal !== null) {
                    for ($j = $position + 1; $j < count($parts); $j++) {
                        if ($parts[$j] === $segments[$i + 1]->literal) {
                            $end = $j;
                            break;
                        }
                    }
                }
                while ($position < $end) {
                    $matched[] = $parts[$position++];
                }
                if ($segment->dimension !== null && in_array($segment->dimension, $this->compiled->dimensionNames(), true)) {
                    $lastGroup = count($matched);
                    $lastToken = $segment->dimension;
                }
            }
            $score = count($matched) * 100 + ($lastGroup ?? 0);
            if ($lastGroup !== null && ($best === null || $score > $best[0])) {
                $prefix = Path::relative('app', $rule->root()->path) ?? $rule->root()->path;
                // The default group root omits its literal prefix; other roots retain theirs.
                $anchorFolder = '@'.$lastToken;
                $suffix = implode('/', array_splice($parts, $lastGroup));
                $candidate = Path::join($anchorFolder, $suffix, Str::kebab($name));
                try {
                    $resolved = $this->parse($candidate.'.stub');
                    if (! Path::same($this->compiled->roots()[$resolved->root]->path, $rule->root()->path)) {
                        $candidate = Path::join($prefix, $anchorFolder, $suffix, Str::kebab($name));
                    }
                    $best = [$score, $candidate];
                } catch (InvalidTemplate) {
                    // Fall back to a literal path below a namespaced root.
                }
            }
        }

        return $best[1] ?? Path::join(Path::relative('app', $folder) ?? $folder, Str::kebab($name));
    }

    /** @return array{writes: string, try: string} */
    public function preview(ParsedTemplate $parsed): array
    {
        $path = Path::join($this->compiled->roots()[$parsed->root]->path, $parsed->in);
        $writes = (string) preg_replace_callback('/\{(\w+)([+?]*)\}/', static fn (array $m): string => '<'.$m[1].(str_contains($m[2], '?') ? '?' : '').'>', $path).'/<Name>.php';
        $groupValues = [];
        $walk = $this->compiled->roots()[$parsed->root]->path;
        foreach (explode('/', $parsed->in) as $segment) {
            if (preg_match('/^\{(\w+)[+?]*\}$/', $segment, $match) === 1) {
                $token = $match[1];
                if (in_array($token, $parsed->groups, true)) {
                    $directory = Path::resolve($this->basePath, $walk);
                    $folders = is_dir($directory) ? array_values(array_filter(scandir($directory) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..' && is_dir(Path::join($directory, $f)))) : [];
                    sort($folders);
                    $value = $folders[0] ?? '<'.$token.'>';
                    $groupValues[] = $value;
                    $walk = Path::join($walk, $value);
                } else {
                    $walk = Path::join($walk, '<'.$token.'>');
                }
            } else {
                $walk = Path::join($walk, $segment);
            }
        }
        $try = 'php artisan mod:'.$parsed->id.' '.($groupValues === [] ? '' : implode('/', $groupValues).':').'<Name>';
        foreach ($parsed->slots as $slot) {
            $try .= ' --'.$slot.'=<'.$slot.'>';
        }

        return compact('writes', 'try');
    }
}
