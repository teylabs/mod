<?php

namespace Tey\Mod\Assets;

use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;

/** @internal Expand entries explicitly, leaving static emission to Laravel's plugin. */
final readonly class AssetInventory
{
    public function __construct(private CompiledLayout $layout, private string $basePath) {}

    /** @return list<array{owner: string, files: list<string>, entries: list<array{declared: string, path: ?string, exists: bool}>}> */
    public function owners(): array
    {
        $owners = [];
        foreach ($this->layout->assets() as $declaration) {
            $files = [];
            foreach ($declaration['files'] as $template) {
                $pattern = $this->glob($template);
                $this->safe($pattern);
                $files[] = $pattern;
            }
            $entries = [];
            foreach ($declaration['entries'] as $template) {
                $matches = $this->entries($template);
                if ($matches === []) {
                    $entries[] = ['declared' => $template, 'path' => null, 'exists' => false];
                }
                foreach ($matches as $path) {
                    $this->safe($path);
                    $entries[] = ['declared' => $template, 'path' => $path, 'exists' => is_file($this->basePath.'/'.$path)];
                }
            }
            $owners[] = ['owner' => $declaration['owner'], 'files' => array_values(array_unique($files)), 'entries' => $entries];
        }

        return $owners;
    }

    /** @return array{generated_by: string, files: list<string>, entries: list<string>} */
    public function build(): array
    {
        $files = [];
        $entries = [];
        foreach ($this->owners() as $owner) {
            $files = [...$files, ...$owner['files']];
            foreach ($owner['entries'] as $entry) {
                if ($entry['exists'] && $entry['path'] !== null) {
                    $entries[] = $entry['path'];
                }
            }
        }
        $files = array_values(array_unique($files));
        $entries = array_values(array_unique($entries));
        sort($files);
        sort($entries);

        return ['generated_by' => 'tey/mod', 'files' => $files, 'entries' => $entries];
    }

    private function glob(string $template): string
    {
        $path = $template;
        foreach ($this->layout->dimensions() as $dimension) {
            $path = str_replace(['{'.$dimension->name.'}', '{'.$dimension->name.'?}'], $dimension->multi ? '**' : '*', $path);
        }
        if (str_contains($path, '{')) {
            throw new InvalidArgumentException('Unknown dimension in asset path ['.$template.'].');
        }

        return $path;
    }

    /** @return list<string> */
    private function entries(string $template): array
    {
        $this->glob($template);
        if (! str_contains($template, '{')) {
            return [$template];
        }
        $prefix = rtrim(explode('{', $template, 2)[0], '/');
        $this->safe($prefix);
        $directory = Path::resolve($this->basePath, $prefix);
        $pattern = preg_quote($template, '~');
        foreach ($this->layout->dimensions() as $dimension) {
            $pattern = str_replace(preg_quote('{'.$dimension->name.'}', '~'), $dimension->multi ? '.+' : '[^/]+', $pattern);
            $pattern = str_replace('/'.preg_quote('{'.$dimension->name.'?}', '~'), $dimension->multi ? '(?:/.+)?' : '(?:/[^/]+)?', $pattern);
        }
        $paths = [];
        if (is_dir($directory)) {
            foreach ((new Finder)->files()->in($directory)->sortByName() as $file) {
                $path = Path::relative($this->basePath, $file->getPathname());
                if ($path !== null && preg_match('~^'.$pattern.'$~D', $path)) {
                    $paths[] = $path;
                }
            }
        }
        // Report optional entries for declared groups, even when no file is present.
        $dimensions = $this->layout->dimensions();
        foreach ((new GroupFolders($this->basePath))->groups($this->layout) as $group) {
            $values = explode('/', $group);
            $path = $template;
            foreach ($dimensions as $index => $dimension) {
                $remaining = $values;
                $value = $dimension->multi ? implode('/', array_splice($remaining, $index)) : ($values[$index] ?? null);
                if ($value !== null) {
                    $path = str_replace(['{'.$dimension->name.'}', '{'.$dimension->name.'?}'], $value, $path);
                }
            }
            if (! str_contains($path, '{')) {
                $paths[] = $path;
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private function safe(string $pattern): void
    {
        $prefix = rtrim(explode('*', $pattern, 2)[0], '/');
        $path = Path::resolve($this->basePath, $prefix);
        $ancestor = $path;
        while (! file_exists($ancestor) && ! is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);
        if ($real === false || Path::relative((string) realpath($this->basePath), $real) === null) {
            throw new InvalidArgumentException('Asset path ['.$pattern.'] escapes the application.');
        }
        if (is_dir($path)) {
            foreach ((new Finder)->in($path)->sortByName() as $file) {
                if ($file->isLink()) {
                    $target = $file->getRealPath();
                    if ($target === false || Path::relative((string) realpath($this->basePath), $target) === null) {
                        throw new InvalidArgumentException('Asset symlink ['.$file->getRelativePathname().'] escapes the application.');
                    }
                }
            }
        }
    }
}
