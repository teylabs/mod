<?php

namespace Tey\Mod\Pages;

use Illuminate\Filesystem\Filesystem;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\FrontendPaths;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/** @internal Page namespaces and paths derived from the compiled frontend declaration. */
final class PageDirectories
{
    /** @var array<string, string>|null */
    private ?array $namespaces = null;

    private FrontendPaths $paths;

    public function __construct(private readonly CompiledLayout $layout, private readonly string $basePath, private readonly Filesystem $files, private readonly PageRoots $packages = new PageRoots)
    {
        $this->paths = new FrontendPaths($basePath);
    }

    /** @return array<string, string> namespace => absolute directory */
    public function namespaces(): array
    {
        return [...($this->namespaces ??= $this->discover()), ...$this->additional()];
    }

    /** @return array<string, string> */
    public function additional(): array
    {
        $namespaces = [];
        foreach ([$this->packages->all(), ...array_map(fn (array $owner): array => $this->discover($owner), $this->layout->frontendOwners())] as $roots) {
            foreach ($roots as $namespace => $path) {
                $relative = $this->paths->relative($path, directory: true);
                if ($relative === null) {
                    continue;
                }
                $directory = $this->basePath.'/'.$relative;
                $existing = $namespaces[$namespace] ?? ($this->namespaces ??= $this->discover())[$namespace] ?? null;
                if ($existing !== null && $existing !== $directory) {
                    $this->paths->skip('page_namespace_conflict', $path, 'Conflicting page namespace ['.$namespace.'].');

                    continue;
                }
                $namespaces[$namespace] = $directory;
            }
        }

        return $namespaces;
    }

    /** @return list<string>|null */
    public function entries(?string $namespace = null): ?array
    {
        if ($namespace === null) {
            return $this->layout->frontend()['page_entries'] ?? null;
        }
        $directory = $this->namespaces()[$namespace] ?? null;
        $package = $this->packages->all()[$namespace] ?? null;
        if ($package !== null && ($relative = $this->paths->relative($package, directory: true)) !== null && $directory === $this->basePath.'/'.$relative) {
            return $this->packages->entries($namespace);
        }
        foreach ($this->layout->frontendOwners() as $owner) {
            if ($directory !== null && ($this->discover($owner)[$namespace] ?? null) === $directory) {
                return $owner['page_entries'] ?? null;
            }
        }

        return $this->layout->frontend()['page_entries'] ?? null;
    }

    /** @return list<array{code: string, path: string, message: string}> */
    public function notices(): array
    {
        $this->namespaces();

        return [...$this->packages->notices(), ...$this->paths->notices()];
    }

    /**
     * @param  array{pages: ?string, page_name: ?string}  $frontend
     * @return array<string, string>
     */
    private function discover(?array $frontend = null): array
    {
        $frontend ??= $this->layout->frontend(new Stack($this->basePath));
        $template = $frontend['pages'];
        $name = $frontend['page_name'];
        if ($template === null || $name === null || ! str_ends_with($name, '::{path}')) {
            return [];
        }
        $pattern = preg_quote($template, '#');
        foreach ($this->layout->dimensions() as $dimension) {
            $capture = '(?P<'.$dimension->name.'>'.($dimension->multi ? '.+' : '[^/]+').')';
            $pattern = str_replace(preg_quote('{'.$dimension->name.'}', '#'), $capture, $pattern);
            $pattern = str_replace('/'.preg_quote('{'.$dimension->name.'?}', '#'), '(?:/'.$capture.')?', $pattern);
        }
        $paths = $this->expand($this->basePath, explode('/', $template));
        $namespaces = [];
        foreach ($paths as $path) {
            $relative = Path::relative($this->basePath, $path);
            if ($relative === null || preg_match('#^'.$pattern.'$#D', $relative, $matches) !== 1) {
                continue;
            }
            $namespace = substr($name, 0, -strlen('::{path}'));
            foreach ($this->layout->dimensionNames() as $dimension) {
                $namespace = str_replace(['{'.$dimension.'}', '{'.$dimension.'?}'], $matches[$dimension] ?? '', $namespace);
            }
            if ($namespace !== '' && ! str_contains($namespace, '{')) {
                $namespaces[$namespace] = $path;
            }
        }

        return $namespaces;
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
        if ($segments === []) {
            return is_dir($directory) ? [$directory] : [];
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
            if ($multi && ! Path::isLink($child)) {
                $paths = [...$paths, ...$this->expand($child, [$segment, ...$segments])];
            }
        }

        return array_values(array_unique($paths));
    }
}
