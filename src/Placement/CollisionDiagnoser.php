<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ResolvedArtifact;

/**
 * Diagnoses path and class collisions before anything is written.
 *
 * The caller supplies what exists (paths and fully qualified class names);
 * the diagnoser never reads the filesystem or the autoloader.
 *
 * @internal used by GenerationPlan::collisions().
 */
final readonly class CollisionDiagnoser
{
    /**
     * @param  iterable<string>  $existing  application-relative paths (ending in .php) and/or fully qualified class names
     * @return list<Collision>
     */
    public function check(ResolvedArtifact $artifact, iterable $existing): array
    {
        $collisions = [];
        $path = $artifact->path();
        $fqcn = $artifact->fqcn();

        foreach ($existing as $entry) {
            $entry = ltrim($entry, '\\');

            $isPath = str_ends_with($entry, '.php') || str_contains($entry, '/');

            if (! $isPath) {
                if ($fqcn !== null && $entry === $fqcn) {
                    $collisions[] = new Collision(CollisionKind::ClassName, $entry, $artifact);
                }

                continue;
            }

            if (Root::normalisePath($entry) === $path) {
                $collisions[] = new Collision(CollisionKind::Path, $entry, $artifact);
            }
        }

        return $collisions;
    }

    /**
     * Collisions between two artifacts that are about to be generated together.
     *
     * @return list<Collision>
     */
    public function between(ResolvedArtifact $first, ResolvedArtifact $second): array
    {
        $existing = [$second->path()];

        if ($second->fqcn() !== null) {
            $existing[] = $second->fqcn();
        }

        return $this->check($first, $existing);
    }
}
