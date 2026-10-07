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
 *
 * Hooks for packages building their own generators on an adapter:
 *
 *  - plansEagerly(): plan in execute() before the native handle() (default),
 *    or let the host call resolvePlan() itself from inside handle(), after
 *    its own preparation (prompts, callbacks) and in its own order.
 *  - beforeGeneration(GenerationPlan) / afterGeneration(GenerationPlan, int):
 *    run around the native generation with the resolved plan.
 *  - getNameInput(): the name without the shorthand prefix; a host may
 *    normalise it further (studly case, say) by overriding it.
 */
trait PlacesGeneratedClass
{
    use InteractsWithPreset;

    private ?GenerationPlan $plan = null;

    public static function supports(ArtifactKind $kind): bool
    {
        return $kind->isClass();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addPlacementOption();
    }

    /**
     * Resolve and check the plan, then let the native command run with it.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->plan;
        $this->plan = null;
        $exitCode = self::FAILURE;

        try {
            if ($this->plansEagerly()) {
                $this->resolvePlan();
            }

            return $exitCode = parent::execute($input, $output);
        } catch (ModException $exception) {
            return $exitCode = $this->reportRefusal($exception);
        } finally {
            $plan = $this->currentPlan();

            if ($plan !== null) {
                $this->afterGeneration($plan, $exitCode);
            }

            $this->plan = $previous;
        }
    }

    /**
     * The plan of the running invocation, once resolved.
     */
    protected function currentPlan(): ?GenerationPlan
    {
        return $this->plan;
    }

    /**
     * Hook: whether the plan is resolved before the native handle() runs.
     */
    protected function plansEagerly(): bool
    {
        return true;
    }

    /**
     * Resolve the plan, refuse collisions per the collision policy and run
     * beforeGeneration(). Idempotent within one invocation.
     *
     * @throws ModException
     */
    protected function resolvePlan(): GenerationPlan
    {
        if ($this->plan !== null) {
            return $this->plan;
        }

        $plan = $this->plan();
        $this->refuseCollisions($plan, $this->hasOption('force') && (bool) $this->option('force'));
        $this->plan = $plan;
        $this->beforeGeneration($plan);

        return $plan;
    }

    /**
     * Hook: before the native generator writes, with the resolved plan.
     */
    protected function beforeGeneration(GenerationPlan $plan): void {}

    /**
     * Hook: after the native generator ran (or was refused), with the plan and the exit code.
     */
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void {}

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
     * The name argument without its placement shorthand prefix.
     *
     * @return string
     */
    protected function getNameInput()
    {
        return $this->shorthand()[1];
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
