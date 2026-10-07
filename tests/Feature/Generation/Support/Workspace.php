<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Preset\Preset;
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
     * @param  string|array<string, mixed>  $layout  a fixture layout name or a raw preset definition
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     */
    public static function run(string|array $layout, callable $callback): mixed
    {
        return OwnedAppRoot::using(function (OwnedAppRoot $root) use ($layout, $callback) {
            app()->setBasePath($root->path);
            config()->set('mod.preset', is_string($layout) ? Layouts::definition($layout) : $layout);
            // The provider resolved the default preset at boot; mod:* reads this one when Artisan starts.
            app()->forgetInstance(Preset::class);

            return $callback(new self($root));
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

        return new CommandResult($exitCode, $output->fetch());
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
     * Whether a generated file refers to a class: imported, or fully qualified
     * (native factory stubs import the model on newer Laravel, qualify it on 11.x).
     */
    public function references(string $relative, string $fqcn): bool
    {
        $source = $this->read($relative);

        return str_contains($source, "use {$fqcn};") || str_contains($source, "\\{$fqcn}");
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
            $relative = substr($item->getPathname(), strlen($this->root->path) + 1);

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
