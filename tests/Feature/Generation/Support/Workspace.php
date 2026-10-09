<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Fixtures\Layouts;
use Tey\Mod\Tests\Support\OwnedAppRoot;

/**
 * A test application rooted in an owned temporary directory, configured with
 * one layout preset. Always created through run(), which destroys the root.
 */
final class Workspace
{
    private function __construct(public readonly OwnedAppRoot $root) {}

    /**
     * @template TReturn
     *
     * @param  string|array<string, mixed>|null  $layout  a fixture layout name, a raw preset definition, or null for the configured layout
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     */
    public static function run(string|array|null $layout, callable $callback): mixed
    {
        return OwnedAppRoot::using(function (OwnedAppRoot $root) use ($layout, $callback) {
            app()->setBasePath($root->path);
            // mod.preset is the provider's internal test hook for raw definitions.
            config()->set('mod.preset', is_string($layout) ? Layouts::definition($layout) : $layout);
            // Forget any preset resolved earlier; mod:* reads this one when Artisan starts.
            app()->forgetInstance(CompiledLayout::class);

            // Mirror the fresh Laravel application's mappings in both the manifest and loader.
            file_put_contents($root->path('composer.json'), json_encode([
                'name' => 'tey-mod/owned-app',
                'autoload' => ['psr-4' => [
                    'App\\' => 'app/',
                    'Database\\Factories\\' => 'database/factories/',
                    'Database\\Seeders\\' => 'database/seeders/',
                ]],
                'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            // Describe autoload roots without loading generated test classes into the process.
            $loader = new ClassLoader;
            $loader->setClassMapAuthoritative(true);
            $loader->addPsr4('App\\', $root->path('app'));
            $loader->addPsr4('Tests\\', $root->path('tests'));
            $loader->addPsr4('Database\\Factories\\', $root->path('database/factories'));
            $loader->addPsr4('Database\\Seeders\\', $root->path('database/seeders'));
            $loader->register();
            try {
                return $callback(new self($root));
            } finally {
                $loader->unregister();
            }
        });
    }

    /**
     * Run a command non-interactively: prompts take their defaults, never read STDIN.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function artisan(string $command, array $parameters = []): CommandResult
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call($command, [...$parameters, '--no-interaction' => true], $output);

        return new CommandResult($exitCode, $output->fetch(), $this->root->path);
    }

    public function read(string $relative): string
    {
        $path = $this->root->path($relative);

        if (! is_file($path)) {
            throw new \RuntimeException("Expected file [{$relative}] was not generated. Files: ".implode(', ', $this->files()));
        }

        return (string) file_get_contents($path);
    }

    /**
     * Whether a generated file refers to a class: imported, or fully qualified.
     */
    public function references(string $relative, string $fqcn): bool
    {
        $source = $this->read($relative);

        return str_contains($source, "use {$fqcn};") || str_contains($source, "\\{$fqcn}");
    }

    /**
     * Delete files, and the folders they leave empty, as if they had never
     * been generated.
     *
     * @param  list<string>  $relatives
     */
    public function remove(array $relatives): void
    {
        foreach ($relatives as $relative) {
            unlink($this->root->path($relative));

            for ($folder = dirname($relative); $folder !== '.' && $folder !== ''; $folder = dirname($folder)) {
                $absolute = $this->root->path($folder);

                if (! is_dir($absolute) || (new FilesystemIterator($absolute))->valid()) {
                    break;
                }

                rmdir($absolute);
            }
        }
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->root->path($relative));
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->root->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * Every generated file, relative to the root (scaffolding excluded).
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root->path, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $relative = (string) Path::relative($this->root->path, $item->getPathname());

            if ($item->isFile() && ! in_array($relative, ['composer.json', '.tey-mod-owned'], true)) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The single migration file in a directory, relative to the root.
     */
    public function migration(string $directory, string $name): string
    {
        $matches = glob($this->root->path($directory).'/*_'.$name.'.php') ?: [];

        if (count($matches) !== 1) {
            throw new \RuntimeException("Expected one [{$name}] migration in [{$directory}], found ".count($matches).'.');
        }

        return $directory.'/'.basename($matches[0]);
    }
}
