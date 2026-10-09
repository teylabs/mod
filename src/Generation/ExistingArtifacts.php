<?php

namespace Tey\Mod\Generation;

use ReflectionClass;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Support\Path;

/**
 * What already exists for a resolved artifact in one application root, in
 * the form CollisionDiagnoser expects (relative paths and class names).
 *
 * Mirrors the native generators' alreadyExists(): the file on disk, and a
 * class the autoloader already knows. A known class is only reported when it
 * lives somewhere other than the target file, so a path collision is never
 * counted twice and --force can still overwrite the file itself.
 *
 * @internal
 */
final readonly class ExistingArtifacts
{
    public function __construct(private string $basePath) {}

    /**
     * @return list<string>
     */
    public function for(ResolvedArtifact $artifact): array
    {
        $existing = [];
        $target = $this->absolute($artifact->path());

        if (file_exists($target)) {
            $existing[] = $artifact->path();
        }

        $fqcn = $artifact->fqcn();

        if ($fqcn !== null && $this->declared($fqcn)) {
            /** @var class-string $fqcn */
            $file = (new ReflectionClass($fqcn))->getFileName();

            if ($file === false || ! Path::same(realpath($file) ?: $file, realpath($target) ?: $target)) {
                $existing[] = $fqcn;
            }
        }

        return $existing;
    }

    public function absolute(string $relativePath): string
    {
        return Path::resolve($this->basePath, $relativePath);
    }

    private function declared(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }
}
