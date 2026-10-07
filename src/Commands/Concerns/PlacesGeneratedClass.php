<?php

namespace Tey\Mod\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Relation\RelationResolution;

/**
 * Places the class a native GeneratorCommand writes.
 *
 * The native command keeps its stubs, options, prompts and buildClass(); only
 * qualifyClass() and getPath() answer from the preset, and the whole plan is
 * checked for collisions before the native handle() writes anything.
 */
trait PlacesGeneratedClass
{
    use InteractsWithPreset;

    private ?GenerationPlan $plan = null;

    public static function supports(ArtifactKind $kind): bool
    {
        return $kind->isClass();
    }

    /**
     * Resolve and check the plan, then let the native command run with it.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->plan;

        try {
            $this->plan = $this->plan();
            $this->refuseCollisions($this->plan, $this->hasOption('force') && (bool) $this->option('force'));

            return parent::execute($input, $output);
        } catch (ModException $exception) {
            return $this->refused($exception);
        } finally {
            $this->plan = $previous;
        }
    }

    protected function plan(): GenerationPlan
    {
        $primary = $this->resolveArtifact($this->kind()->id, $this->getNameInput(), $this->placementContext());

        return new GenerationPlan($primary, $this->plannedRelations($primary));
    }

    /**
     * Relations this invocation's options follow. Resolved up front so
     * collisions in related artifacts refuse before the primary is written.
     *
     * @return list<RelationResolution>
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        return [];
    }

    protected function primary(): ResolvedArtifact
    {
        return $this->plan !== null ? $this->plan->primary : $this->resolveArtifact($this->kind()->id, $this->getNameInput(), $this->placementContext());
    }

    /**
     * The planned relation with the given id, if this invocation follows it.
     */
    protected function plannedRelation(string $relationId): ?RelationResolution
    {
        foreach ($this->plan !== null ? $this->plan->relations : [] as $resolution) {
            if ($resolution->relation->id === $relationId) {
                return $resolution;
            }
        }

        return null;
    }

    /**
     * Planned relations to the given kind.
     *
     * @return list<RelationResolution>
     */
    protected function plannedRelationsTo(string $kindId): array
    {
        return array_values(array_filter(
            $this->plan !== null ? $this->plan->relations : [],
            static fn (RelationResolution $resolution): bool => $resolution->relation->toKind === $kindId,
        ));
    }

    /**
     * @param  string  $name
     * @return string
     */
    protected function qualifyClass($name)
    {
        if (trim($name) === $this->getNameInput()) {
            return (string) $this->primary()->fqcn();
        }

        return parent::qualifyClass($name);
    }

    /**
     * @param  string  $name
     * @return string
     */
    protected function getPath($name)
    {
        $primary = $this->primary();

        if ($name === $primary->fqcn()) {
            return $this->existingArtifacts()->absolute($primary->path());
        }

        return parent::getPath($name);
    }
}
