<?php

namespace Tey\Mod\Tests\Feature\Discovery\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Pest\TestSuite;
use RuntimeException;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Fixtures\Layouts;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/**
 * An owned app root with hand-written classes, isolated per test.
 *
 * Every declared namespace is prefixed with a namespace unique to this
 * fixture ({{ns}} in sources), so classes written by one test never collide
 * with another test's in the same PHP process. A private autoloader serves
 * the declared roots and is removed again on destroy().
 */
final class DiscoveryFixture
{
    private ?Closure $autoloader = null;

    private function __construct(
        public readonly OwnedAppRoot $root,
        public readonly string $namespace,
    ) {}

    public static function create(): self
    {
        return new self(OwnedAppRoot::create(), 'ModFx'.bin2hex(random_bytes(6)));
    }

    /**
     * Wrap a test body: it receives a fresh fixture that is destroyed when the body ends, even when it fails.
     *
     * @param-closure-this TestCase $test
     */
    public static function around(Closure $test): Closure
    {
        return function () use ($test): void {
            $case = TestSuite::getInstance()->test;

            if (! $case instanceof TestCase) {
                throw new RuntimeException('DiscoveryFixture::around() wraps Testbench feature tests only.');
            }

            $fixture = DiscoveryFixture::create();

            try {
                $test->call($case, $fixture);
            } finally {
                $fixture->destroy();
            }
        };
    }

    /**
     * Compile a layout definition with every namespace moved under this fixture's prefix.
     *
     * @param  array<string, mixed>  $definition
     */
    public function preset(array $definition): Preset
    {
        $definition = $this->prefixed($definition);
        $this->autoload($definition);

        return Preset::fromArray($definition);
    }

    public function layout(string $name): Preset
    {
        return $this->preset(Layouts::definition($name));
    }

    /**
     * The fixture-qualified class name: class('App\Providers\X') → ModFx…\App\Providers\X.
     */
    public function class(string $fqcn): string
    {
        return $this->namespace.'\\'.ltrim($fqcn, '\\');
    }

    /**
     * Write a hand-written source file; {{ns}} is replaced by the fixture namespace.
     */
    public function write(string $relativePath, string $source): self
    {
        $path = $this->root->path($relativePath);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, str_replace('{{ns}}', $this->namespace, $source));

        return $this;
    }

    /**
     * A string bound in the container by a fixture provider.
     */
    public static function binding(Application $app, string $key): ?string
    {
        $value = $app->bound($key) ? $app->make($key) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * What fixture listeners recorded, as "Listener@method:Event".
     *
     * @return list<string>
     */
    public static function handled(Application $app): array
    {
        $handled = $app->bound('fixture.handled') ? $app->make('fixture.handled') : [];

        return is_array($handled) ? array_values(array_filter($handled, is_string(...))) : [];
    }

    public function path(string $relative = ''): string
    {
        return $this->root->path($relative);
    }

    public function destroy(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }

        $this->root->destroy();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function prefixed(array $definition): array
    {
        foreach (['roots', 'excluded'] as $section) {
            if (! isset($definition[$section]) || ! is_array($definition[$section])) {
                continue;
            }

            foreach ($definition[$section] as $key => $root) {
                if (is_array($root) && isset($root['namespace']) && is_string($root['namespace'])) {
                    $root['namespace'] = $this->namespace.'\\'.$root['namespace'];
                    $definition[$section][$key] = $root;
                }
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function autoload(array $definition): void
    {
        $map = [];

        foreach ((array) ($definition['roots'] ?? []) as $root) {
            if (is_array($root) && is_string($root['namespace'] ?? null) && is_string($root['path'] ?? null)) {
                $map[rtrim($root['namespace'], '\\').'\\'] = $root['path'];
            }
        }

        // Longest prefix first, like Composer.
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }

        $base = $this->root->path;
        $this->autoloader = static function (string $class) use ($map, $base): void {
            foreach ($map as $prefix => $path) {
                if (str_starts_with($class, $prefix)) {
                    $file = $base.'/'.$path.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                    if (is_file($file)) {
                        require $file;

                        return;
                    }
                }
            }
        };

        spl_autoload_register($this->autoloader);
    }
}
