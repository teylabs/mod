<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Support;

use FilesystemIterator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Pest\TestSuite;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseMatch;
use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/**
 * One layout, end to end, through the service provider only: an owned app
 * root, fresh application boots configured with the layout's preset, mod:*
 * calls, and an autoloader for the classes generated into the root.
 *
 * The autoloader is derived from the preset's own roots, so it knows nothing
 * about any particular layout. Generated classes are loaded into this PHP
 * process: tests give them unique names (see tag()).
 */
final class AcceptanceApp
{
    public readonly Preset $preset;

    public readonly string $tag;

    private ?Application $app = null;

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $discovery  mod.discovery settings
     */
    private function __construct(
        private readonly TestCase $case,
        public readonly OwnedAppRoot $root,
        public readonly array $definition,
        private readonly array $discovery,
    ) {
        $this->preset = Preset::fromArray($definition);
        $this->tag = 'T'.bin2hex(random_bytes(4));
    }

    /**
     * @template TReturn
     *
     * @param  array<string, mixed>  $definition  a raw preset definition
     * @param  callable(self): TReturn  $callback
     * @param  array<string, mixed>  $discovery  extra mod.discovery settings
     * @return TReturn
     */
    public static function run(array $definition, callable $callback, array $discovery = []): mixed
    {
        $case = TestSuite::getInstance()->test;

        if (! $case instanceof TestCase) {
            throw new RuntimeException('Acceptance tests need the Testbench test case.');
        }

        return OwnedAppRoot::using(function (OwnedAppRoot $root) use ($case, $definition, $discovery, $callback) {
            $app = new self($case, $root, $definition, $discovery);
            $autoload = $app->autoloader();

            spl_autoload_register($autoload);

            try {
                return $callback($app);
            } finally {
                spl_autoload_unregister($autoload);
            }
        });
    }

    /**
     * Boot a fresh application on the root: providers register, discovery runs.
     *
     * @param  array<string, mixed>  $discovery  mod.discovery settings for this boot only
     */
    public function boot(array $discovery = []): Application
    {
        return $this->app = $this->case->bootApplicationUsing(function (Application $app) use ($discovery): void {
            $config = $app->make('config');
            $app->setBasePath($this->root->path);
            $config->set('mod.preset', $this->definition);
            $config->set('mod.discovery', [...(array) $config->get('mod.discovery', []), 'enabled' => true, ...$this->discovery, ...$discovery]);
        });
    }

    public function app(): Application
    {
        return $this->app ?? $this->boot();
    }

    public function discovery(): Discovery
    {
        return $this->app()->make(Discovery::class);
    }

    /**
     * Run an artisan command non-interactively on the current application.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function artisan(string $command, array $parameters = []): CommandResult
    {
        $output = new BufferedOutput;
        $exitCode = $this->app()->make(Kernel::class)->call($command, [...$parameters, '--no-interaction' => true], $output);

        return new CommandResult($exitCode, $output->fetch());
    }

    /**
     * Artisan command names that the current application has registered under mod:.
     *
     * @return list<string>
     */
    public function modCommands(): array
    {
        $names = array_keys($this->app()->make(Kernel::class)->all());
        $mod = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'mod:')));
        sort($mod);

        return $mod;
    }

    /**
     * Whether the current application's Artisan has a command of this class.
     */
    public function hasArtisanCommand(string $class): bool
    {
        foreach ($this->app()->make(Kernel::class)->all() as $command) {
            if ($command instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function mapPath(string $relative): ReverseMatch
    {
        return (new ReverseMapper($this->preset))->fromPath($relative);
    }

    public function mapClass(string $fqcn): ReverseMatch
    {
        return (new ReverseMapper($this->preset))->fromClass($fqcn);
    }

    /**
     * Assert that the core maps a file back to the kind and placement it came
     * from, by path and (for classes) by class name, to the same artifact.
     *
     * @param  array<string, string>  $context
     */
    public function assertOwned(string $relative, string $kind, array $context = [], ?string $fqcn = null): void
    {
        $byPath = $this->mapPath($relative);

        expect($byPath->isMatched())->toBeTrue("{$relative}: {$byPath->outcome->value} ".($byPath->reason ?? ''))
            ->and($byPath->artifact?->kind->id)->toBe($kind, $relative)
            ->and($byPath->artifact?->context->toArray())->toBe($context, $relative)
            ->and($byPath->artifact?->path())->toBe($relative);

        if ($fqcn !== null) {
            $byClass = $this->mapClass($fqcn);

            expect($byPath->artifact?->fqcn())->toBe($fqcn, $relative)
                ->and($byClass->isMatched())->toBeTrue("{$fqcn}: {$byClass->outcome->value}")
                ->and($byClass->artifact !== null && $byPath->artifact !== null && $byClass->artifact->equals($byPath->artifact))->toBeTrue($fqcn);
        }
    }

    public function read(string $relative): string
    {
        $path = $this->root->path($relative);

        if (! is_file($path)) {
            throw new RuntimeException("Expected file [{$relative}] does not exist. Files: ".implode(', ', $this->files()));
        }

        return (string) file_get_contents($path);
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
     * Write a hand-authored PHP class (never generated by mod) and return its path.
     */
    public function handWrite(string $relative, string $namespace, string $body): string
    {
        $this->write($relative, "<?php\n\nnamespace {$namespace};\n\n{$body}\n");

        return $relative;
    }

    /**
     * Every file under app/ and database/, relative to the root, sorted.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach (['app', 'database'] as $top) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root->path($top), FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $item */
            foreach ($items as $item) {
                if ($item->isFile()) {
                    $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($this->root->path) + 1));
                }
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
            throw new RuntimeException("Expected one [{$name}] migration in [{$directory}], found ".count($matches).'.');
        }

        return $directory.'/'.basename($matches[0]);
    }

    /**
     * PSR-4 loading for the preset's namespaced roots inside the owned root.
     */
    private function autoloader(): \Closure
    {
        $map = [];

        foreach ($this->preset->roots() as $root) {
            if ($root->namespace !== null) {
                $map[$root->namespace] = $root->path;
            }
        }

        // Longest prefix first, so nested roots win over their parents.
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return function (string $class) use ($map): void {
            foreach ($map as $namespace => $path) {
                if (str_starts_with($class, $namespace)) {
                    $file = $this->root->path($path.'/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php');

                    if (is_file($file)) {
                        require $file;
                    }

                    return;
                }
            }
        };
    }
}
