<?php

namespace Tey\Mod\Views;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Support\Path;

/** @internal Locate declared view folders without class discovery or loading PHP files. */
final readonly class ViewDirectories
{
    public function __construct(private CompiledLayout $layout, private string $basePath) {}

    /** @return list<array{group: ?string, namespace: ?string, path: string, context: PlacementContext}> */
    public function entries(): array
    {
        $template = $this->layout->frontend()['views'];
        if ($template === null) {
            return [];
        }
        $pattern = preg_quote($template, '#');
        foreach ($this->layout->dimensions() as $dimension) {
            $capture = '(?P<'.$dimension->name.'>'.($dimension->multi ? '.+' : '[^/]+').')';
            $pattern = str_replace(preg_quote('{'.$dimension->name.'}', '#'), $capture, $pattern);
            $pattern = str_replace('/'.preg_quote('{'.$dimension->name.'?}', '#'), '(?:/'.$capture.')?', $pattern);
        }
        $first = strpos($template, '{');
        $prefix = $first === false ? $template : rtrim(substr($template, 0, $first), '/');
        $directory = Path::resolve($this->basePath, $prefix);
        if (! is_dir($directory)) {
            return [];
        }
        $paths = [$directory];
        if ($first !== false) {
            foreach ((new Finder)->directories()->in($directory)->sortByName() as $folder) {
                $paths[] = $folder->getPathname();
            }
        }
        $entries = [];
        $groups = (new GroupFolders($this->basePath))->groups($this->layout);
        foreach ($paths as $path) {
            $relative = Path::relative($this->basePath, $path);
            if ($relative === null || ! preg_match('#^'.$pattern.'$#D', $relative, $matches)) {
                continue;
            }
            $values = [];
            foreach ($this->layout->dimensionNames() as $dimension) {
                if (isset($matches[$dimension]) && $matches[$dimension] !== '') {
                    $value = $matches[$dimension];
                    if (str_contains($template, '{'.$dimension.'?}')) {
                        if (in_array($value, ['components', 'vendor'], true)) {
                            continue 2;
                        }
                        $canonical = array_values(array_filter($groups, static fn (string $group): bool => Str::kebab($group) === $value && $group !== strtolower($group)));
                        $value = $canonical[0] ?? Str::studly($value);
                    }
                    $values[$dimension] = $value;
                }
            }
            $group = implode('/', $values) ?: null;
            $entries[] = ['group' => $group, 'namespace' => ViewIdentity::namespace($group), 'path' => $relative, 'context' => PlacementContext::of($values)];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $entries;
    }
}
