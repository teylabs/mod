<?php

namespace Tey\Mod\Install;

use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\FrontendPaths;
use Tey\Mod\Support\Stack;

/** Declare inspectable frontend wiring without writing from a provider. @api */
final class Wiring
{
    /** @var array<string, string>
     */
    private array $aliases = [];

    /** @var list<string>
     */
    private array $sources = [];

    /** @var list<string>
     */
    private array $styles = [];

    private FrontendPaths $paths;

    /** @internal */
    public function __construct(private readonly string $basePath)
    {
        $this->paths = new FrontendPaths($basePath);
    }

    public function __clone(): void
    {
        $this->paths = clone $this->paths;
    }

    /** Add an explicit Vite alias and matching TypeScript path. @api */
    public function alias(string $name, string $directory): self
    {
        if (preg_match('~^[@A-Za-z_][@A-Za-z0-9_./-]*$~D', $name) !== 1) {
            $this->paths->skip('frontend_alias_invalid', $directory, 'Use a literal import alias without glob characters.');

            return $this;
        }
        $directory = $this->paths->relative($directory);
        if ($directory === null) {
            return $this;
        }
        if (isset($this->aliases[$name]) && $this->aliases[$name] !== $directory) {
            $this->paths->skip('frontend_alias_conflict', $directory, 'Conflicting import alias ['.$name.'].');

            return $this;
        }
        $this->aliases[$name] = $directory;

        return $this;
    }

    /** Add a Tailwind source directory; this does not import styles. @api */
    public function source(string $directory): self
    {
        if (($relative = $this->paths->relative($directory)) !== null) {
            $this->sources[] = $relative;
        }

        return $this;
    }

    /** A compiled owner may contain layout dimension globs. @internal */
    public function sourcePattern(string $pattern): self
    {
        if (preg_match('~^/|^[A-Za-z]:|(?:^|/)\.\.(?:/|$)|[\x00-\x1f\x27"`]~', $pattern)) {
            $this->paths->skip('frontend_path_invalid', $pattern, 'Frontend sources must stay inside the application.');

            return $this;
        }
        if ($this->paths->relative(rtrim(explode('*', $pattern, 2)[0], '/')) !== null) {
            $this->sources[] = $pattern;
        }

        return $this;
    }

    /** Explicitly import an application-selected stylesheet in its CSS entry. @api */
    public function css(string $file): self
    {
        if (($relative = $this->paths->relative($file)) !== null) {
            $this->styles[] = $relative;
        }

        return $this;
    }

    /** Build a read-only plan, also consumed by mod:install. @api */
    public function plan(): WiringPlan
    {
        return new WiringPlan($this->basePath, (new WiringEdits($this->basePath, app(CompiledLayout::class), new Stack($this->basePath), FrontendWiring::forLayout($this, app(CompiledLayout::class))))->plan());
    }

    /**
     * @internal
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        $aliases = $this->aliases;
        uksort($aliases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $aliases;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return array_values(array_unique($this->sources));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function styles(): array
    {
        return array_values(array_unique($this->styles));
    }

    /**
     * @internal
     *
     * @return list<array{code: string, path: string, message: string}>
     */
    public function notices(): array
    {
        return $this->paths->notices();
    }
}
