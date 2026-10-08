<?php

namespace Tey\Mod\Commands\Concerns;

use LogicException;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\CollisionPolicy;
use Tey\Mod\Generation\ExistingArtifacts;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\CollisionKind;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationPolicy;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;

/**
 * What every mod:* adapter shares: the preset and kind it generates, the
 * placement input (--in or the "Group:Name" shorthand), placement,
 * relations and the collision check before writing.
 *
 * Everything a host package needs to build its own generator on top of an
 * adapter is a protected hook here:
 *
 *  - resolvePreset() / kindId(): which preset and kind this invocation is
 *    for, when the command is not bound through forKind() (a host may
 *    resolve them per invocation from its own configuration).
 *  - placementInput() / placementContext(): where the placement comes from.
 *    The default reads `--in` or the shorthand prefix of the name argument;
 *    a host may derive it from its own options or prompts.
 *  - placementOptionName(): the option added for placement (`in`), or null
 *    for a host that provides placement its own way.
 *  - collisionPolicy(): refuse the plan up front, or leave it to the native
 *    generator.
 *  - reportRefusal() / reportReference(): the console output of refusals
 *    and reference-only relations.
 *
 * Those hooks (together with the ones PlacesGeneratedClass and
 * MigrationCommand document) are the contract. The other protected methods
 * here are helpers the adapters share; a subclass may call them, but their
 * signatures may change between minor releases.
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
        $this->addPlacementOption(replace: true);

        return $this;
    }

    /**
     * The preset this invocation generates against. Bound by forKind(), or
     * resolved by the host through resolvePreset().
     */
    protected function preset(): Preset
    {
        return $this->modPreset ??= $this->resolvePreset();
    }

    /**
     * The kind this invocation generates. Bound by forKind(), or the kind
     * named by kindId() in the preset.
     */
    protected function kind(): ArtifactKind
    {
        return $this->modKind ?? $this->preset()->kind($this->kindId());
    }

    /**
     * Hook: the preset when the command was not bound through forKind().
     */
    protected function resolvePreset(): Preset
    {
        throw new LogicException(static::class.' is not bound to a layout kind; bind it with forKind() or override resolvePreset().');
    }

    /**
     * Hook: the kind id when the command was not bound through forKind().
     * A host may decide it per invocation (from its input, say).
     */
    protected function kindId(): string
    {
        throw new LogicException(static::class.' is not bound to a layout kind; bind it with forKind() or override kindId().');
    }

    /**
     * Hook: the name of the placement option, or null when the host supplies placement its own way.
     */
    protected function placementOptionName(): ?string
    {
        return 'in';
    }

    /**
     * Add the placement option once, with the preset's dimensions in its
     * description; `replace` refreshes the description once the preset is bound.
     */
    protected function addPlacementOption(bool $replace = false): void
    {
        $option = $this->placementOptionName();

        if ($option === null) {
            return;
        }

        if ($this->getDefinition()->hasOption($option)) {
            if (! $replace) {
                return;
            }

            $options = $this->getDefinition()->getOptions();
            unset($options[$option]);
            $this->getDefinition()->setOptions(array_values($options));
        }

        $dimensions = $this->modPreset?->dimensionNames() ?? [];

        $this->getDefinition()->addOption(new InputOption(
            $option,
            null,
            InputOption::VALUE_REQUIRED,
            $dimensions === []
                ? 'Placement (this layout declares no placement groups)'
                : 'Placement: '.implode('/', $dimensions).' values in that order, separated by "/" (folders inside a multi-segment value separated by "."); or prefix the name with "<placement>:"',
        ));
    }

    /**
     * The raw name argument, before the shorthand prefix is split off.
     */
    protected function rawNameInput(): string
    {
        $argument = $this->hasArgument('name') ? $this->argument('name') : null;

        return is_string($argument) ? trim($argument) : '';
    }

    /**
     * The "Group:Name" shorthand split at the first colon: [placement|null, name].
     *
     * @return array{0: ?string, 1: string}
     */
    protected function shorthand(): array
    {
        $raw = $this->rawNameInput();
        $colon = strpos($raw, ':');

        if ($colon === false) {
            return [null, $raw];
        }

        return [trim(substr($raw, 0, $colon)), trim(substr($raw, $colon + 1))];
    }

    /**
     * Hook: the placement input in --in syntax, or null for none.
     *
     * The default accepts `--in` or the shorthand prefix of the name
     * argument ("Billing:Invoice" ≡ "Invoice --in=Billing"), never both.
     */
    protected function placementInput(): ?string
    {
        [$prefix] = $this->shorthand();
        $option = $this->placementOptionName();
        $value = $option !== null && $this->hasOption($option) ? $this->option($option) : null;
        $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

        if ($prefix !== null && $value !== null) {
            throw GenerationRefused::because(sprintf(
                'Placement was given twice: as the prefix [%s:] of the name and as --%s=%s. Use one of them.',
                $prefix,
                $option,
                $value,
            ));
        }

        if ($prefix !== null && $this->preset()->dimensionNames() === []) {
            throw GenerationRefused::because(sprintf(
                'Layout [%s] has no placement groups; drop the [%s:] prefix.',
                $this->layoutName(),
                $prefix,
            ));
        }

        if ($prefix === '') {
            throw GenerationRefused::because('The placement prefix before ":" is empty.');
        }

        return $prefix ?? $value;
    }

    /**
     * Hook: where the primary artifact is placed.
     */
    protected function placementContext(): PlacementContext
    {
        return PlacementContext::fromOption($this->placementInput() ?? '', $this->preset());
    }

    /**
     * Hook: the layout's name for messages.
     */
    protected function layoutName(): string
    {
        $name = $this->laravel->make('config')->get('mod.layout', 'laravel');

        return is_string($name) ? $name : 'layout';
    }

    /**
     * Hook: refuse the plan on any collision (default) or leave it to the native generator.
     */
    protected function collisionPolicy(): CollisionPolicy
    {
        return CollisionPolicy::Refuse;
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
     * given. A bare name is placed as that kind with this invocation's
     * placement, narrowed to the dimensions the kind's rule reads; a
     * slash-nested name is refused by placement with the --in hint unless the
     * kind accepts nested names.
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
     * command of the kind that owns it. Classes no rule places are refused.
     */
    protected function generateOwnedClass(string $fqcn, string $kindId): void
    {
        $match = (new ReverseMapper($this->preset()))->fromClass($fqcn);
        $artifact = $match->isMatched() ? $match->artifact : null;

        if ($artifact === null || $artifact->kind->id !== $kindId || $artifact->kind->command === null) {
            throw GenerationRefused::because("Cannot generate [{$fqcn}]: no {$kindId} rule of the layout places it ({$match->reason}).");
        }

        $exitCode = $this->call($artifact->kind->command, $this->argumentsFor($artifact));

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
     * Generate a related artifact through its own command, or report a reference.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $target = $resolution->target ?? throw new LogicException('Only resolved relations can be followed.');

        if ($resolution->policy() !== RelationPolicy::Generate) {
            $this->reportReference($target);

            return;
        }

        $command = $this->preset()->kind($target->kind->id)->command;

        if ($command === null) {
            throw GenerationRefused::because("Kind [{$target->kind->id}] declares no command to generate [{$target->describe()}].");
        }

        $exitCode = $this->call($command, [
            ...$this->argumentsFor($target),
            ...array_filter($arguments, static fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''),
        ]);

        if ($exitCode !== 0) {
            throw GenerationRefused::because("Generating related {$target->kind->id} [{$target->describe()}] failed.");
        }
    }

    /**
     * Hook: the arguments that make a child command generate exactly the given artifact.
     *
     * The default passes the nested name and the placement through `--in`
     * (or through the shorthand prefix when the layout has no placement
     * option). A host whose commands take placement differently overrides it.
     *
     * @return array<string, mixed>
     */
    protected function argumentsFor(ResolvedArtifact $target): array
    {
        $placement = $this->inOption($target->context);
        $option = $this->placementOptionName();

        if ($placement === '') {
            return ['name' => $target->nestedName()];
        }

        if ($option === null) {
            return ['name' => $placement.':'.$target->nestedName()];
        }

        return ['name' => $target->nestedName(), '--'.$option => $placement];
    }

    /**
     * The --in value that reproduces a placement context.
     */
    protected function inOption(PlacementContext $context): string
    {
        $values = [];
        $gap = false;

        foreach ($this->preset()->dimensions() as $dimension) {
            $value = $context->get($dimension->name);

            if ($value === null) {
                $gap = true;

                continue;
            }

            if ($gap) {
                throw GenerationRefused::because("Placement [{$context->describe()}] cannot be expressed with --in.");
            }

            $values[] = $dimension->multi ? str_replace('/', '.', $value) : $value;
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
        if ($this->collisionPolicy() === CollisionPolicy::Native) {
            return;
        }

        $collisions = $plan->collisions(new CollisionDiagnoser, $this->existingArtifacts(), $overwritePrimary);

        if ($collisions !== []) {
            throw GenerationRefused::collisions($collisions,
                duplicatePrimary: ! $overwritePrimary && count($collisions) === 1
                    && $collisions[0]->kind === CollisionKind::Path
                    && $collisions[0]->artifact->equals($plan->primary),
            );
        }
    }

    /**
     * Hook: how a refusal is reported; returns the exit code.
     */
    protected function reportRefusal(ModException $exception): int
    {
        foreach (explode(PHP_EOL, $exception->getMessage()) as $line) {
            $this->components->error($line);
        }

        return $exception instanceof GenerationRefused && $exception->duplicatePrimary ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Hook: how a reference-only relation is reported.
     */
    protected function reportReference(ResolvedArtifact $target): void
    {
        $this->components->info(sprintf(
            'Related %s [%s] is a reference; not generated.',
            $target->kind->id,
            $target->fqcn() ?? $target->path(),
        ));
    }
}
