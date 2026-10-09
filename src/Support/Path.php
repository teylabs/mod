<?php

namespace Tey\Mod\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Separator-neutral filesystem paths. Normalization is lexical: it does not
 * resolve symlinks or parent-directory segments.
 *
 * @internal shared path operations, not namespace mapping.
 */
final class Path
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $unc = str_starts_with($path, '//');
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = (string) preg_replace('#(^|/)\./#', '$1', $path);

        if ($unc) {
            $path = '/'.$path;
        }

        if ($path === '/' || preg_match('#^[A-Za-z]:/$#', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/');
    }

    public static function join(string ...$parts): string
    {
        $path = '';

        foreach ($parts as $part) {
            $part = self::normalize($part);

            if ($part === '') {
                continue;
            }

            $path = $path === '' ? $part : rtrim($path, '/').'/'.ltrim($part, '/');
        }

        return self::normalize($path);
    }

    /** Resolve a project path while preserving an absolute target. */
    public static function resolve(string $base, string $path): string
    {
        return (new Filesystem)->isAbsolutePath($path)
            ? self::normalize($path)
            : self::join($base, $path);
    }

    public static function same(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /** The remainder below the base, or null for a different directory tree. */
    public static function relative(string $base, string $path): ?string
    {
        $base = self::normalize($base);
        $path = self::normalize($path);

        if (in_array('..', explode('/', $path), true)) {
            return null;
        }

        if (self::same($base, $path)) {
            return '';
        }

        if ($base === '') {
            return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1 ? null : $path;
        }

        $prefix = rtrim($base, '/').'/';

        return self::same(substr($path, 0, strlen($prefix)), $prefix) ? substr($path, strlen($prefix)) : null;
    }
}
