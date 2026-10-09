<?php

namespace Tey\Mod\Scaffolds;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Support\Path;

/** @internal scoped to one scaffold invocation, shared with the normal adapters */
final class ScaffoldExecution
{
    public bool $planning = true;

    public bool $nestedNames = false;

    /** @var array<string, GenerationPlan> primary path => accepted plan */
    public array $plans = [];

    /** @var array<string, string> primary path => ordinary generator template */
    public array $defaultStubs = [];

    /** @var array<string, string> primary path => selected variant file */
    public array $variants = [];

    /** @var array<string, ResolvedArtifact> */
    public array $aliases = [];

    /** @var list<string> */
    public array $keep = [];

    /** @var array<string, list<ResolvedArtifact>> */
    public array $bases = [];

    /** @var array<string, string> notices held until their primary is generated */
    public array $groupNotices = [];

    /** @var list<string> dimension and group name, independent of the member root */
    public array $newGroups = [];

    public bool $force = false;

    /** @var array<string, array<string, mixed>> path => values scoped to that node */
    public array $values = [];

    private ?GenerationPlan $collected = null;

    public function reset(): void
    {
        $this->collected = null;
    }

    public function collected(): ?GenerationPlan
    {
        return $this->collected;
    }

    public function accepted(ResolvedArtifact $candidate): ?GenerationPlan
    {
        foreach ($this->plans as $plan) {
            $primary = $plan->primary;
            if ($primary->kind->id === $candidate->kind->id && $primary->name === $candidate->name
                && $primary->nested === $candidate->nested && $primary->context->equals($candidate->context)) {
                return $plan;
            }
        }

        return null;
    }

    public function collect(GenerationPlan $plan, string $stub): void
    {
        $this->collected = $plan;
        $this->plans[$plan->primary->path()] = $plan;
        $this->defaultStubs[$plan->primary->path()] = $stub;
    }

    public function keeps(ResolvedArtifact $artifact): bool
    {
        foreach ($this->keep as $path) {
            if (Path::same($artifact->path(), $path)) {
                return true;
            }
        }

        return false;
    }

    public function replace(string $stub, ResolvedArtifact $self): string
    {
        $values = $this->values[$self->path()] ?? $this->aliases;
        // Leave Laravel's own class placeholder to its native generator.
        foreach ($values as $key => $value) {
            if ($value instanceof ResolvedArtifact && $value->equals($self)) {
                unset($values[$key]);
            }
        }

        return (new Placeholders($values))->render($stub);
    }
}
