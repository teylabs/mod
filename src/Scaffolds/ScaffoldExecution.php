<?php

namespace Tey\Mod\Scaffolds;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Generation\GenerationPlan;

/** @internal scoped to one scaffold invocation, shared with the normal adapters */
final class ScaffoldExecution
{
    public bool $planning = true;

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
        return in_array($artifact->path(), $this->keep, true);
    }

    public function replace(string $stub, ResolvedArtifact $self): string
    {
        return (string) preg_replace_callback('/\{\{\s*([\w-]+)(?:\.(fqcn|camel|snake|kebab|studly|plural))?\s*\}\}/', function (array $match) use ($self): string {
            $artifact = $this->aliases[$match[1]] ?? null;
            if ($artifact === null || $artifact->equals($self)) {
                return $match[0];
            }
            $name = class_basename($artifact->fqcn() ?? $artifact->name);

            return match ($match[2] ?? '') {
                'fqcn' => $artifact->fqcn() ?? $name,
                'camel' => Str::camel($name),
                'snake' => Str::snake($name),
                'kebab' => Str::kebab($name),
                'studly' => Str::studly($name),
                'plural' => Str::pluralStudly($name),
                default => $name,
            };
        }, $stub);
    }
}
