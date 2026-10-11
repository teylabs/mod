<?php

namespace Tey\Mod\Install;

use Tey\Mod\Assets\AssetInventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;

/** @internal Static wiring for the supported Laravel Vite plugin generation. */
final readonly class AssetEdits
{
    private const GENERATED = 'resources/mod-assets.json';

    public function __construct(private string $basePath, private CompiledLayout $layout) {}

    public function plan(?Plan $plan = null): Plan
    {
        $plan ??= new Plan('mod:install', name: 'assets');
        if ($this->layout->assets() === []) {
            return $plan;
        }
        $build = (new AssetInventory($this->layout, $this->basePath))->build();
        $json = json_encode($build, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $before = $this->read(self::GENERATED);
        $existing = $before === '' ? null : json_decode($before, true);
        if ($before !== '' && (! is_array($existing) || ($existing['generated_by'] ?? null) !== 'tey/mod')) {
            $plan->warning('Refusing to replace application-owned ['.self::GENERATED.'].');

            return $plan;
        }
        if ($before !== $json) {
            $plan->file('asset declarations', 'frontend-wiring', self::GENERATED, ['before' => $before, 'after' => $json], $before !== '');
        }
        $vite = is_file($this->basePath.'/vite.config.ts') ? 'vite.config.ts' : 'vite.config.js';
        $before = $this->read($vite);
        foreach ($plan->files as $file) {
            if ($file['path'] === $vite && is_string($file['identity']['after'] ?? null)) {
                $before = $file['identity']['after'];
            }
        }
        if (! $this->plugin3()) {
            $plan->warning('Automatic static asset wiring requires laravel-vite-plugin 3. Keep ordinary imports, or configure declared static assets manually with a verified adapter. Executable entries: '.implode(', ', $build['entries']).'.', file: $vite);

            return $plan;
        }
        $after = $this->config($before);
        if ($after === null) {
            $plan->warning('Could not confirm ['.$vite.']; add '.$this->text('import').' and merge ...modAssets.entries into laravel input, and ...modAssets.files into laravel assets. Keep central inputs and existing plugin options. Declared files: '.implode(', ', $build['files']).'; entries: '.implode(', ', $build['entries']).'.', file: $vite);
        } elseif ($before !== $after) {
            $original = $this->read($vite);
            $plan->files = array_values(array_filter($plan->files, static fn (array $file): bool => $file['path'] !== $vite));
            $plan->file('Vite assets and entries', 'frontend-wiring', $vite, ['before' => $original, 'after' => $after], true);
        }

        return $plan;
    }

    /** @return array{static: bool, entries: bool, declarations: bool} */
    public function status(): array
    {
        $vite = $this->read(is_file($this->basePath.'/vite.config.ts') ? 'vite.config.ts' : 'vite.config.js');
        $expected = (new AssetInventory($this->layout, $this->basePath))->build();

        return ['static' => $this->plugin3() && str_contains($this->active($vite), 'modAssets.files'), 'entries' => str_contains($this->active($vite), 'modAssets.entries'), 'declarations' => json_decode($this->read(self::GENERATED), true) === $expected];
    }

    private function plugin3(): bool
    {
        $package = json_decode($this->read('package.json'), true);
        if (! is_array($package)) {
            return false;
        }
        foreach (['devDependencies', 'dependencies'] as $section) {
            $dependencies = $package[$section] ?? null;
            $version = is_array($dependencies) ? ($dependencies['laravel-vite-plugin'] ?? null) : null;
            if (is_string($version) && preg_match('/^[~^]?3(?:\.|$)/', $version)) {
                return true;
            }
        }

        return false;
    }

    private function config(string $before): ?string
    {
        $contents = str_replace("\r\n", "\n", $before);
        $active = $this->active($contents);
        if (str_contains($active, $this->text('import')) && str_contains($active, 'modAssets.files') && str_contains($active, 'modAssets.entries')) {
            return $before;
        }
        if (substr_count($active, 'laravel({') !== 1 || str_contains($active, 'modAssets')) {
            return null;
        }
        // Accept literal arrays only; arbitrary expressions require an explicit manual edit.
        $pattern = '~\blaravel\(\{(?P<options>(?:[^{}\'"`]|[\'"`](?:\\\\.|[^\'"`\\\\])*[\'"`])*)\}\)~s';
        if (preg_match($pattern, $contents, $match) !== 1) {
            return null;
        }
        $options = $match['options'];
        if (preg_match('~\binput:\s*\[(?P<items>[^\]]*)\]~s', $options, $input) !== 1) {
            return null;
        }
        if ($this->active($input['items']) !== $input['items']) {
            return null;
        }
        $items = rtrim(trim($input['items']), ',');
        $options = str_replace($input[0], 'input: ['.$items.($items === '' ? '' : ', ').'...modAssets.entries]', $options);
        if (preg_match('/\bassets\s*:/', $options)) {
            if (preg_match('~\bassets:\s*\[(?P<items>[^\]]*)\]~s', $options, $assets) !== 1) {
                return null;
            }
            if ($this->active($assets['items']) !== $assets['items']) {
                return null;
            }
            $items = rtrim(trim($assets['items']), ',');
            $options = str_replace($assets[0], 'assets: ['.$items.($items === '' ? '' : ', ').'...modAssets.files]', $options);
        } else {
            $options = ' assets: modAssets.files,'.$options;
        }
        $contents = $this->text('import')."\n".str_replace($match[0], 'laravel({'.$options.'})', $contents);

        return str_contains($before, "\r\n") ? str_replace("\n", "\r\n", $contents) : $contents;
    }

    private function active(string $contents): string
    {
        return (string) preg_replace_callback('~[\'"](?:\\\\.|[^\'"\\\\])*[\'"]|`(?:\\\\.|[^`\\\\])*`|//[^\r\n]*|/\*.*?\*/~s', static fn (array $match): string => str_starts_with($match[0], '//') || str_starts_with($match[0], '/*') ? ' ' : $match[0], $contents);
    }

    private function text(string $key): string
    {
        $content = json_decode((string) file_get_contents(__DIR__.'/../../resources/install/assets.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($content) || ! is_string($content[$key] ?? null)) {
            throw new \LogicException('Missing asset installer content.');
        }

        return $content[$key];
    }

    private function read(string $path): string
    {
        return is_file($this->basePath.'/'.$path) ? (string) file_get_contents($this->basePath.'/'.$path) : '';
    }
}
