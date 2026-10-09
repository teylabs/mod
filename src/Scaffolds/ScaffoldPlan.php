<?php

namespace Tey\Mod\Scaffolds;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\ExistingArtifacts;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\CollisionKind;

/** @internal combines the normal generator plans without changing placement */
final class ScaffoldPlan
{
    /** @var list<array{artifact: ResolvedArtifact, alias: string}> */
    private array $files = [];

    public function add(string $alias, GenerationPlan $plan, ScaffoldExecution $scope): void
    {
        $this->files[] = ['artifact' => $plan->primary, 'alias' => $alias];
        foreach ($scope->bases[$plan->primary->path()] ?? [] as $base) {
            if (! in_array($base->path(), array_map(static fn (array $file): string => $file['artifact']->path(), $this->files), true)) {
                $this->files[] = ['artifact' => $base, 'alias' => $alias.' (base)'];
            }
        }
        $generated = $plan->generated();
        usort($generated, static fn (ResolvedArtifact $a, ResolvedArtifact $b): int => ($a->kind->id === 'migration' ? -1 : 0) <=> ($b->kind->id === 'migration' ? -1 : 0));
        foreach ($generated as $artifact) {
            $child = $scope->accepted($artifact) ?? new GenerationPlan($artifact);
            $this->add($alias.' ('.$artifact->kind->id.')', $child, $scope);
        }
    }

    /** @return list<array{artifact: ResolvedArtifact, alias: string}> */
    public function files(): array
    {
        return $this->files;
    }

    /** Reject identity clashes; return existing paths the user can keep or overwrite.
     * @return list<string>
     */
    public function existing(ExistingArtifacts $existing): array
    {
        $diagnoser = new CollisionDiagnoser;
        $paths = [];
        $written = [];
        foreach ($this->files as ['artifact' => $artifact]) {
            foreach ($diagnoser->check($artifact, $existing->for($artifact)) as $collision) {
                if ($collision->kind !== CollisionKind::Path) {
                    throw GenerationRefused::collisions([$collision]);
                }
                $paths[] = $artifact->path();
            }
            foreach ($written as $other) {
                $collisions = $diagnoser->between($artifact, $other);
                if ($collisions !== []) {
                    throw GenerationRefused::collisions($collisions);
                }
            }
            $written[] = $artifact;
        }

        return array_values(array_unique($paths));
    }
}
