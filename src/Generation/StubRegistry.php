<?php

namespace Tey\Mod\Generation;

use Illuminate\Support\ServiceProvider;

/**
 * Stubs that packages register for a kind, in any layout:
 *
 *     Mod::stubs()->for('record', Stub::file(__DIR__.'/stubs/record.stub'));
 *
 * Precedence when generating: the application's published
 * `stubs/mod.<kind>.stub`, then the stub registered last for the kind, then
 * the stub the layout declares, then the generator's own. The registry keeps
 * which service provider registered each stub.
 */
final class StubRegistry
{
    /** @var array<string, array{stub: Stub, by: ?string}> */
    private array $stubs = [];

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
