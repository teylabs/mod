<?php

namespace Tey\Mod\Commands\Concerns;

use LogicException;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\ExistingArtifacts;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GenerationRefused;
use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationPolicy;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;

/**
 * What every mod:* adapter shares: the preset and kind it generates, the
 * --in option, placement, relations and the collision check before writing.
 */
trait InteractsWithPreset
{
    private ?Preset $modPreset = null;

    private ?ArtifactKind $modKind = null;

    public function forKind(Preset $preset, ArtifactKind $kind): static
    {
        if ($kind->command === null) {
            throw new LogicException("Kind [{$kind->id}] declares no command name.");
        }

        $this->modPreset = $preset;
        $this->modKind = $kind;

        $this->setName($kind->command);
        $this->setAliases([]);

        $dimensions = $preset->dimensionNames();

        $this->getDefinition()->addOption(new InputOption(
            'in',
            null,
            InputOption::VALUE_REQUIRED,
            $dimensions === []
                ? 'Placement (this layout declares no dimensions)'
                : 'Placement: '.implode('/', $dimensions).' values in that order, separated by "/" (folders inside a multi-segment value separated by ".")',
        ));

        return $this;
    }

    protected function preset(): Preset
    {
        return $this->modPreset ?? throw new LogicException(static::class.' is not bound to a layout kind.');
    }

    protected function kind(): ArtifactKind
    {
        return $this->modKind ?? throw new LogicException(static::class.' is not bound to a layout kind.');
    }

    protected function placementContext(): PlacementContext
    {
        $in = $this->option('in');

        return PlacementContext::fromOption(is_string($in) ? $in : '', $this->preset());
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    protected function resolveArtifact(string $kindId, string $name, PlacementContext $context, array $attributes = []): ResolvedArtifact
    {
        return (new PlacementResolver($this->preset()))->resolve(ArtifactRequest::for($kindId, $name, $context, $attributes));
    }

    /**
     * The class an option such as --model or --event names.
     *
     * A namespaced name (App\\Models\\Invoice, \\Illuminate\\...) is used as
     * given. A bare name is placed as that kind with this invocation's --in,
     * narrowed to the dimensions the kind's rule reads; a slash-nested name
     * is refused by placement with the --in hint.
     */
    protected function placeSibling(string $kindId, string $name): string
    {
        $name = trim($name);

        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (! $this->preset()->hasKind($kindId)) {
            throw GenerationRefused::because("The layout declares no [{$kindId}] kind to place [{$name}]; pass its fully qualified class name.");
        }

        $context = $this->placementContext()->only($this->preset()->rule($kindId)->dimensions());

        return (string) $this->resolveArtifact($kindId, $name, $context)->fqcn();
    }

    /**
     * Where an existing or planned class lives, when a preset rule owns it.
     */
    protected function ownedPathOf(string $fqcn): ?string
    {
        $match = (new ReverseMapper($this->preset()))->fromClass($fqcn);

        return $match->isMatched() && $match->artifact !== null ? $match->artifact->path() : null;
    }

    /**
     * Whether a class exists: known to the autoloader, or written where the preset places it.
     */
    protected function classExists(string $fqcn): bool
    {
        if (class_exists($fqcn)) {
            return true;
        }

        $path = $this->ownedPathOf($fqcn);

        return $path !== null && is_file($this->existingArtifacts()->absolute($path));
    }

    /**
     * Generate a class an option names (a missing --model, say) through the
     * mod:* command of the kind that owns it. Classes no rule places are refused.
     */
    protected function generateOwnedClass(string $fqcn, string $kindId): void
    {
        $match = (new ReverseMapper($this->preset()))->fromClass($fqcn);
        $artifact = $match->isMatched() ? $match->artifact : null;

        if ($artifact === null || $artifact->kind->id !== $kindId || $artifact->kind->command === null) {
            throw GenerationRefused::because("Cannot generate [{$fqcn}]: no {$kindId} rule of the layout places it ({$match->reason}).");
        }

        $exitCode = $this->call($artifact->kind->command, array_filter([
            'name' => $artifact->name,
            '--in' => $this->inOption($artifact->context),
        ], static fn (string $value): bool => $value !== ''));

        if ($exitCode !== 0) {
            throw GenerationRefused::because("Generating {$kindId} [{$fqcn}] failed.");
        }
    }

    /**
     * Every declared relation from the source to the given kind, resolved.
     *
     * Following an option without a declared relation is refused: mod never
     * guesses where a related artifact belongs.
     *
     * @param  array<string, string|int|float|bool|null>  $attributes
     * @return list<RelationResolution>
     */
    protected function relationsTo(ResolvedArtifact $source, string $toKind, ?string $name = null, array $attributes = [], bool $required = true): array
    {
        $resolver = new RelationResolver($this->preset(), new PlacementResolver($this->preset()));
        $resolutions = [];

        foreach ($this->preset()->relationsFrom($source->kind->id) as $relation) {
            if ($relation->toKind !== $toKind || $relation->policy === RelationPolicy::None) {
                continue;
            }

            $resolution = $resolver->resolve($source, $relation->id, $name, $attributes);

            if (! $resolution->isResolved()) {
                throw GenerationRefused::because("Relation [{$relation->id}] cannot be followed: {$resolution->reason}.");
            }

            $resolutions[] = $resolution;
        }

        if ($resolutions === [] && $required) {
            throw GenerationRefused::because("The layout declares no relation from [{$source->kind->id}] to [{$toKind}].");
        }

        return $resolutions;
    }

    /**
     * Generate a related artifact through its own mod:* command, or report a reference.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $target = $resolution->target ?? throw new LogicException('Only resolved relations can be followed.');

        if ($resolution->policy() !== RelationPolicy::Generate) {
            $this->components->info(sprintf(
                'Related %s [%s] is a reference; not generated.',
                $target->kind->id,
                $target->fqcn() ?? $target->path(),
            ));

            return;
        }

        $command = $this->preset()->kind($target->kind->id)->command;

        if ($command === null) {
            throw GenerationRefused::because("Kind [{$target->kind->id}] declares no command to generate [{$target->describe()}].");
        }

        $exitCode = $this->call($command, array_filter([
            'name' => $target->name,
            '--in' => $this->inOption($target->context),
            ...$arguments,
        ], static fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''));

        if ($exitCode !== 0) {
            throw GenerationRefused::because("Generating related {$target->kind->id} [{$target->describe()}] failed.");
        }
    }

    /**
     * The --in value that reproduces a placement context.
     */
    protected function inOption(PlacementContext $context): string
    {
        $values = [];
        $gap = false;

        foreach ($this->preset()->dimensionNames() as $dimension) {
            $value = $context->get($dimension);

            if ($value === null) {
                $gap = true;

                continue;
            }

            if ($gap) {
                throw GenerationRefused::because("Placement [{$context->describe()}] cannot be expressed with --in.");
            }

            $values[] = $value;
        }

        return implode('/', $values);
    }

    protected function existingArtifacts(): ExistingArtifacts
    {
        return new ExistingArtifacts($this->laravel->basePath());
    }

    /**
     * @throws GenerationRefused
     */
    protected function refuseCollisions(GenerationPlan $plan, bool $overwritePrimary): void
    {
        $collisions = $plan->collisions(new CollisionDiagnoser, $this->existingArtifacts(), $overwritePrimary);

        if ($collisions !== []) {
            throw GenerationRefused::collisions($collisions);
        }
    }

    protected function refused(ModException $exception): int
    {
        foreach (explode(PHP_EOL, $exception->getMessage()) as $line) {
            $this->components->error($line);
        }

        return self::FAILURE;
    }
}
