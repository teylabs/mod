<?php

namespace Tey\Mod\Translations;

use Illuminate\Filesystem\Filesystem;
use Tey\Mod\Discovery\DiscoveryCache;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Views\ViewIdentity;

/** @internal Probe declared language folders only; memoized for one application scope. */
final class TranslationDirectories
{
    /** @var list<array{group: ?string, namespace: string, path: string}>|null */
    private ?array $entries = null;

    public function __construct(private readonly CompiledLayout $layout, private readonly string $basePath, private readonly Filesystem $files, private readonly ?DiscoveryCache $cache = null) {}

    /** @return list<array{group: ?string, namespace: string, path: string}> */
    public function entries(): array
    {
        return $this->entries ??= $this->cache?->translations(PresetFingerprint::of($this->layout)) ?? $this->discover();
    }

    /** Fresh probes for mod:cache, even when a previous folder inventory is cached.
     * @return list<array{group: ?string, namespace: string, path: string}>
     */
    public function discover(): array
    {
        $template = $this->layout->frontend()['lang'];
        if ($template === null) {
            return [];
        }
        $pattern = preg_quote(Path::resolve($this->basePath, $template), '#');
        foreach ($this->layout->dimensions() as $dimension) {
            $capture = '(?P<'.$dimension->name.'>'.($dimension->multi ? '.+' : '[^/]+').')';
            $pattern = str_replace(preg_quote('{'.$dimension->name.'}', '#'), $capture, $pattern);
            $pattern = str_replace('/'.preg_quote('{'.$dimension->name.'?}', '#'), '(?:/'.$capture.')?', $pattern);
        }
        $first = strpos($template, '{');
        $prefix = $first === false ? $template : rtrim(substr($template, 0, $first), '/');
        $segments = $first === false ? [] : explode('/', ltrim(substr($template, strlen($prefix)), '/'));
        $paths = $this->expand(Path::resolve($this->basePath, $prefix), $segments);
        $entries = [];
        foreach ($paths as $path) {
            $relative = Path::relative($this->basePath, $path);
            if (preg_match('#^'.$pattern.'$#D', Path::normalize($path), $matches) !== 1) {
                continue;
            }
            $values = [];
            foreach ($this->layout->dimensionNames() as $dimension) {
                if (isset($matches[$dimension]) && $matches[$dimension] !== '') {
                    $values[] = $matches[$dimension];
                }
            }
            $group = implode('/', $values) ?: null;
            $namespace = ViewIdentity::namespace($group);
            if ($namespace !== null) {
                $entries[] = ['group' => $group, 'namespace' => $namespace, 'path' => $relative ?? $path];
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * Literal segments are direct probes; single dimensions list only immediate
     * children. Multi dimensions may descend further because nested groups are valid.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function expand(string $directory, array $segments): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        if ($segments === []) {
            return [$directory];
        }
        $segment = array_shift($segments);
        if (preg_match('/^\{([^}?]+)(\?)?\}$/D', $segment, $matches) !== 1) {
            $path = $directory.'/'.$segment;

            return is_dir($path) ? $this->expand($path, $segments) : [];
        }
        $paths = isset($matches[2]) ? $this->expand($directory, $segments) : [];
        $multi = false;
        foreach ($this->layout->dimensions() as $dimension) {
            if ($dimension->name === $matches[1]) {
                $multi = $dimension->multi;
                break;
            }
        }
        foreach ($this->files->directories($directory) as $child) {
            $paths = [...$paths, ...$this->expand($child, $segments)];
            if ($multi && ! is_link($child) && ! in_array(basename($child), $segments, true)) {
                $paths = [...$paths, ...$this->expand($child, [$segment, ...$segments])];
            }
        }

        return array_values(array_unique($paths));
    }
}
