<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Support\Path;

/**
 * A declared root: a PSR-4 namespace prefix with its directory, or a
 * directory alone for class-less files (migrations, routes).
 *
 * @api
 */
final readonly class CompiledRoot
{
    /** @param list<self> $exceptions subtrees claimed inside an exclusion */
    private function __construct(
        /** @api */
        public ?string $namespace,
        /** @api */
        public string $path,
        private array $exceptions = [],
    ) {}

    /**
     * @internal
     *
     * @param  list<self>  $exceptions
     */
    public function except(array $exceptions): self
    {
        return new self($this->namespace, $this->path, $exceptions);
    }

    /**
     * @internal
     *
     * @return list<self>
     */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    /** Keep walking an excluded directory if it contains a claimed subtree. @internal */
    public function canTraverse(string $path): bool
    {
        foreach ($this->exceptions as $exception) {
            if (Path::relative($path, $exception->path) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @internal */
    public static function psr4(string $namespace, string $path): self
    {
        return new self(rtrim($namespace, '\\').'\\', self::normalisePath($path));
    }

    /** @internal */
    public static function files(string $path): self
    {
        return new self(null, self::normalisePath($path));
    }

    /** @api */
    public function isClassRoot(): bool
    {
        return $this->namespace !== null;
    }

    /**
     * The namespace remainder below this root, or null when the class is not under it.
     *
     * @api
     */
    public function namespaceRemainder(string $fqcn): ?string
    {
        if ($this->namespace === null || ! str_starts_with($fqcn, $this->namespace)) {
            return null;
        }

        foreach ($this->exceptions as $exception) {
            if ($exception->namespaceRemainder($fqcn) !== null) {
                return null;
            }
        }

        return substr($fqcn, strlen($this->namespace));
    }

    /**
     * The path remainder below this root, or null when the path is not under it.
     *
     * @api
     */
    public function pathRemainder(string $path): ?string
    {
        foreach ($this->exceptions as $exception) {
            if ($exception->pathRemainder($path) !== null) {
                return null;
            }
        }

        return Path::relative($this->path, $path);
    }

    /**
     * Join namespace segments below this root. Returns the root namespace without its trailing separator.
     *
     * @param  list<string>  $segments
     *
     * @internal
     */
    public function namespaceFor(array $segments): string
    {
        return rtrim((string) $this->namespace.implode('\\', $segments), '\\');
    }

    /**
     * @param  list<string>  $segments
     *
     * @internal
     */
    public function pathFor(array $segments, string $file): string
    {
        return Path::join($this->path, ...[...$segments, $file]);
    }

    /** @api */
    public static function normalisePath(string $path): string
    {
        return Path::normalize($path);
    }
}
