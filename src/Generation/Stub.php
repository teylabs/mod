<?php

namespace Tey\Mod\Generation;

use Closure;

/**
 * The stub a kind's classes are generated from, with optional variants and a base class.
 *
 *     Stub::file(__DIR__.'/stubs/record.stub')
 *         ->whenInstalled('vendor/records', base: 'Vendor\Records\Record')
 *         ->whenClass(App\Support\Record::class, stub: __DIR__.'/stubs/record.app.stub')
 *         ->generatesBase(GeneratedBase::named('Record', in: 'Shared/Records', stub: __DIR__.'/stubs/bases/record.stub'));
 *
 * When generating, the first rule that applies decides:
 *
 *  1. a configured base (`mod.bases.<kind>`, `->base(config:)` or
 *     `->base(class:)`): the class extends it, nothing is generated;
 *  2. the first variant whose package is installed (or class exists): its stub and
 *     base; no base class is generated;
 *  3. the generated base: written on first use, then extended;
 *  4. the plain stub.
 *
 * A stub fills `{{ base }}` (the base's full class name), `{{ baseClass }}` (its
 * short name), `{{ baseImport }}` (its `use` line, or nothing) and `{{ extends }}`
 * (` extends <baseClass>`, or nothing) besides `{{ namespace }}` and `{{ class }}`.
 */
final class Stub
{
    /** @var list<array{package: ?string, class: ?string, base: ?string, stub: ?string}> */
    private array $variants = [];

    private ?string $baseClass = null;

    private ?string $baseConfig = null;

    private ?GeneratedBase $generatedBase = null;

    private ?string $label = null;

    private function __construct(public readonly string $path) {}

    /** @internal preserve base selection while rendering an edited template. */
    public function forTemplate(string $path): self
    {
        $stub = new self($path);
        $stub->baseClass = $this->baseClass;
        $stub->baseConfig = $this->baseConfig;
        $stub->generatedBase = $this->generatedBase;
        $stub->variants = array_map(static fn (array $variant): array => [...$variant, 'stub' => $path], $this->variants);

        return $stub;
    }

    /**
     * The plain stub, used when no variant applies.
     */
    public static function file(string $path): self
    {
        return new self($path);
    }

    /**
     * When the Composer package is installed, extend `base` and/or use `stub` instead.
     */
    public function whenInstalled(string $package, ?string $base = null, ?string $stub = null): self
    {
        $this->variants[] = ['package' => $package, 'class' => null, 'base' => $base, 'stub' => $stub];

        return $this;
    }

    /**
     * When the class exists, extend `base` and/or use `stub` instead.
     */
    public function whenClass(string $class, ?string $base = null, ?string $stub = null): self
    {
        $this->variants[] = ['package' => null, 'class' => $class, 'base' => $base, 'stub' => $stub];

        return $this;
    }

    /**
     * Always extend this base: a class name, or the config key that holds one.
     */
    public function base(?string $class = null, ?string $config = null): self
    {
        $this->baseClass = $class;
        $this->baseConfig = $config;

        return $this;
    }

    /**
     * Generate this base into the application on first use and extend it.
     */
    public function generatesBase(GeneratedBase $base): self
    {
        $this->generatedBase = $base;

        return $this;
    }

    /**
     * The noun a kind generated from this stub prints, unless the kind has its own label.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @internal
     */
    public function labelText(): ?string
    {
        return $this->label;
    }

    /**
     * @internal the base generated when no explicit base or variant applies
     */
    public function generatedBase(): ?GeneratedBase
    {
        return $this->generatedBase;
    }

    /**
     * @internal decide which branch applies; `$config` reads a config key
     *
     * @param  Closure(string): mixed  $config
     */
    public function choose(PackageDetector $detector, Closure $config, ?string $configured): StubChoice
    {
        $explicit = $this->filled($configured)
            ?? ($this->baseConfig !== null ? $this->filled($config($this->baseConfig)) : null)
            ?? $this->filled($this->baseClass);

        if ($explicit !== null) {
            return new StubChoice($this->path, $explicit, message: "Using the configured base {$explicit}.");
        }

        foreach ($this->variants as $variant) {
            $found = $variant['package'] !== null
                ? $detector->isInstalled($variant['package'])
                : $detector->classExists((string) $variant['class']);

            if (! $found) {
                continue;
            }

            $message = $variant['package'] !== null
                ? "Using {$variant['package']} (installed)."
                : 'Using '.ltrim((string) $variant['class'], '\\').'.';

            return new StubChoice($variant['stub'] ?? $this->path, $this->filled($variant['base']), message: $message);
        }

        return new StubChoice($this->path, generatedBase: $this->generatedBase);
    }

    private function filled(mixed $class): ?string
    {
        return is_string($class) && trim($class, ' \\') !== '' ? ltrim(trim($class), '\\') : null;
    }
}
