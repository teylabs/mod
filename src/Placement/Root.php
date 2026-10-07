<?php

namespace Tey\Mod\Placement;

/**
 * A declared root: a PSR-4 namespace prefix with its directory, or a
 * directory alone for class-less files (migrations, routes).
 */
final readonly class Root
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
        $prefix = $this->path === '' ? '' : $this->path.'/';

        if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
            return null;
        }

        return substr($path, strlen($prefix));
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
        return implode('/', array_filter([$this->path, ...$segments, $file], static fn (string $part): bool => $part !== ''));
    }

    public static function normalisePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        return trim($path, '/');
    }
}
