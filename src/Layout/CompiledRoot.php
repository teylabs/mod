<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Support\Path;

/**
 * A declared root: a PSR-4 namespace prefix with its directory, or a
 * directory alone for class-less files (migrations, routes).
 */
final readonly class CompiledRoot
{
    private function __construct(
        public ?string $namespace,
        public string $path,
    ) {}

    public static function psr4(string $namespace, string $path): self
    {
        return new self(rtrim($namespace, '\\').'\\', self::normalisePath($path));
    }

    public static function files(string $path): self
    {
        return new self(null, self::normalisePath($path));
    }

    public function isClassRoot(): bool
    {
        return $this->namespace !== null;
    }

    /**
     * The namespace remainder below this root, or null when the class is not under it.
     */
    public function namespaceRemainder(string $fqcn): ?string
    {
        if ($this->namespace === null || ! str_starts_with($fqcn, $this->namespace)) {
            return null;
        }

        return substr($fqcn, strlen($this->namespace));
    }

    /**
     * The path remainder below this root, or null when the path is not under it.
     */
    public function pathRemainder(string $path): ?string
    {
        return Path::relative($this->path, $path);
    }

    /**
     * Join namespace segments below this root. Returns the root namespace without its trailing separator.
     *
     * @param  list<string>  $segments
     */
    public function namespaceFor(array $segments): string
    {
        return rtrim((string) $this->namespace.implode('\\', $segments), '\\');
    }

    /**
     * @param  list<string>  $segments
     */
    public function pathFor(array $segments, string $file): string
    {
        return Path::join($this->path, ...[...$segments, $file]);
    }

    public static function normalisePath(string $path): string
    {
        return trim(Path::normalize($path), '/');
    }
}
