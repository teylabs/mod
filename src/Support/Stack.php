<?php

namespace Tey\Mod\Support;

/**
 * Reads the host's installed frontend declarations without changing them.
 *
 * @api
 */
final readonly class Stack
{
    /** @internal */
    public function __construct(private string $basePath) {}

    /** The Inertia adapter declared by the app, or null for an app without Inertia. @api */
    public function inertia(): ?string
    {
        $file = Path::join($this->basePath, 'package.json');
        $package = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($package)) {
            return null;
        }
        $dependencies = [];
        foreach (['dependencies', 'devDependencies'] as $key) {
            if (is_array($package[$key] ?? null)) {
                $dependencies += $package[$key];
            }
        }

        return match (true) {
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@inertiajs/svelte']) => 'svelte',
            default => null,
        };
    }

    /** Whether Composer declares Livewire or Flux. @api */
    public function livewire(): bool
    {
        $file = Path::join($this->basePath, 'composer.json');
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($composer)) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            $packages = $composer[$section] ?? null;
            if (is_array($packages) && (isset($packages['livewire/livewire']) || isset($packages['livewire/flux']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same stack record exposed by mod:list --json.
     *
     * @return array{inertia: ?string, typescript: bool, pages: string, livewire: bool}
     *
     * @api
     */
    public function toArray(): array
    {
        return ['inertia' => $this->inertia(), 'typescript' => $this->typescript(), 'pages' => $this->pagesPath(), 'livewire' => $this->livewire()];
    }

    /** @api */
    public function typescript(): bool
    {
        return is_file(Path::join($this->basePath, 'tsconfig.json'));
    }

    /** @api */
    public function pagesPath(): string
    {
        $directory = Path::join($this->basePath, 'resources/js');
        // Inspect directory entries rather than is_dir: macOS and Windows may ignore case.
        $entries = is_dir($directory) ? scandir($directory) : [];
        $casing = is_array($entries) && in_array('Pages', $entries, true) && ! in_array('pages', $entries, true) ? 'Pages' : 'pages';

        return 'resources/js/'.$casing;
    }
}
