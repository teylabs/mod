<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Support\Path;

/** @internal Portable refusal of escaping, linked and case-ambiguous paths. */
final readonly class PathGuard
{
    public function __construct(private string $basePath) {}

    public function problem(string $path): ?string
    {
        $normal = Path::normalize($path);
        if ($normal === '' || Path::relative('', $normal) === null || in_array('..', explode('/', $normal), true) || str_contains($normal, "\0") || str_contains($normal, ':')) {
            return "mod:rename path {$path} is outside the project or unsafe. Configure a project-relative root. Nothing was written.";
        }
        $parent = $this->basePath;
        foreach (explode('/', $normal) as $part) {
            if (file_exists($parent) && ! is_dir($parent)) {
                return "mod:rename path {$path} has a non-directory parent. Choose another target. Nothing was written.";
            }
            $next = Path::join($parent, $part);
            if (Path::isLink($next)) {
                return "mod:rename path {$path} traverses a symlink. Use project-owned paths without symlinks. Nothing was written.";
            }
            if (is_dir($parent)) {
                $matches = array_values(array_filter(scandir($parent) ?: [], static fn (string $name): bool => strcasecmp($name, $part) === 0));
                if (count($matches) > 1 || ($matches !== [] && $matches[0] !== $part)) {
                    return 'mod:rename cannot safely perform this case-only path move on this filesystem. Choose a temporary distinct name first. Nothing was written.';
                }
            }
            $parent = $next;
        }

        return null;
    }
}
