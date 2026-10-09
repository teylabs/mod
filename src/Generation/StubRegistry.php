<?php

namespace Tey\Mod\Generation;

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Support\Path;

/**
 * Stubs that packages register for a kind, in any layout:
 *
 *     Mod::stubs()->for('record', Stub::file(__DIR__.'/stubs/record.stub'));
 *
 * Precedence when generating: the application's published
 * `stubs/mod.<kind>.stub`, then the stub registered last for the kind, then
 * the stub the layout declares, then the starter for the kind, then the
 * generator's own. The registry keeps which service provider registered
 * each stub.
 */
final class StubRegistry
{
    /** @var array<string, array{stub: Stub, by: ?string}> */
    private array $stubs = [];

    /** @var array<string, Stub> */
    private array $starters = [];

    /** @var array<string, ?string> template folder => registering provider */
    private array $folders = [];

    /** Add a package's generator template folder. The application's templates win. */
    public function folder(string $path): self
    {
        $this->folders[Path::normalize($path)] = $this->caller();

        return $this;
    }

    /** @return array<string, ?string> */
    public function folders(): array
    {
        return $this->folders;
    }

    public function for(string $kind, Stub $stub): self
    {
        $this->stubs[$kind] = ['stub' => $stub, 'by' => $this->caller()];

        return $this;
    }

    public function get(string $kind): ?Stub
    {
        return $this->stubs[$kind]['stub'] ?? null;
    }

    /**
     * @internal the starter a kind of this id gets in any layout, below a layout's own stub
     */
    public function starter(string $kind, Stub $stub): self
    {
        $this->starters[$kind] = $stub;

        return $this;
    }

    /**
     * @internal
     */
    public function starterFor(string $kind): ?Stub
    {
        return $this->starters[$kind] ?? null;
    }

    /**
     * @internal the Stub a kind is generated from: registered, else the layout's, else the starter
     */
    public function resolve(string $kind, ?Stub $layoutStub): ?Stub
    {
        return $this->get($kind) ?? $layoutStub ?? $this->starterFor($kind);
    }

    /**
     * The service provider class that registered the kind's stub, when one did.
     */
    public function registeredBy(string $kind): ?string
    {
        return $this->stubs[$kind]['by'] ?? null;
    }

    /**
     * Where an application publishes its own stub for the kind, relative to the base path.
     */
    public function publishedPath(string $kind): string
    {
        return "stubs/mod.{$kind}.stub";
    }

    private function caller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;

            if (is_string($class) && is_subclass_of($class, ServiceProvider::class)) {
                return $class;
            }
        }

        return null;
    }
}
