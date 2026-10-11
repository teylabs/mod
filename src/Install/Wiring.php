<?php

namespace Tey\Mod\Install;

use InvalidArgumentException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
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

    /** @internal */
    public function __construct(private readonly string $basePath) {}

    /** Add an explicit Vite alias and matching TypeScript path. @api */
    public function alias(string $name, string $directory): self
    {
        if (preg_match('~^[@A-Za-z_][@A-Za-z0-9_./-]*$~D', $name) !== 1) {
            throw new InvalidArgumentException('Use a literal import alias without glob characters.');
        }
        $directory = $this->relative($directory);
        if (isset($this->aliases[$name]) && $this->aliases[$name] !== $directory) {
            throw new InvalidArgumentException('Conflicting import alias ['.$name.'].');
        }
        $this->aliases[$name] = $directory;

        return $this;
    }

    /** Add a Tailwind source directory; this does not import styles. @api */
    public function source(string $directory): self
    {
        $this->sources[] = $this->relative($directory);

        return $this;
    }

    /** A compiled owner may contain layout dimension globs. @internal */
    public function sourcePattern(string $pattern): self
    {
        if (preg_match('~^/|^[A-Za-z]:|(?:^|/)\.\.(?:/|$)|[\x00-\x1f\x27"`]~', $pattern)) {
            throw new InvalidArgumentException('Frontend sources must stay inside the application.');
        }
        $this->relative(rtrim(explode('*', $pattern, 2)[0], '/'));
        $this->sources[] = $pattern;

        return $this;
    }

    /** Explicitly import an application-selected stylesheet in its CSS entry. @api */
    public function css(string $file): self
    {
        $this->styles[] = $this->relative($file);

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

    private function relative(string $path): string
    {
        $path = Path::normalize($path);
        $relative = Path::relative($this->basePath, $path) ?? $path;
        if ($relative === '' || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\x27"`]|^[A-Za-z]:|^/|[{}*?]~', $relative) === 1) {
            throw new InvalidArgumentException('Frontend paths must be literal paths inside the application.');
        }
        $ancestor = $this->basePath.'/'.$relative;
        while (! file_exists($ancestor) && ! is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);
        if ($real === false || Path::relative((string) realpath($this->basePath), $real) === null) {
            throw new InvalidArgumentException('Frontend paths cannot follow a symlink outside the application.');
        }

        return rtrim($relative, '/');
    }
}
