<?php

namespace Tey\Mod\Support;

/**
 * Reads the host's installed frontend declarations without changing them.
 *
 * @internal consumed by frontend generators and the installer.
 */
final readonly class Stack
{
    public function __construct(private string $basePath) {}

    /** The Inertia adapter declared by the app, or null for an app without Inertia. */
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
            default => null,
        };
    }

    public function typescript(): bool
    {
        return is_file(Path::join($this->basePath, 'tsconfig.json'));
    }

    public function pagesPath(): string
    {
        $directory = Path::join($this->basePath, 'resources/js');
        // Inspect directory entries rather than is_dir: macOS and Windows may ignore case.
        $entries = is_dir($directory) ? scandir($directory) : [];
        $casing = is_array($entries) && in_array('Pages', $entries, true) && ! in_array('pages', $entries, true) ? 'Pages' : 'pages';

        return 'resources/js/'.$casing;
    }
}
