<?php

namespace Tey\Mod\Commands\Concerns;

use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\DimensionNotApplicable;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\CollisionPolicy;
use Tey\Mod\Generation\ExistingArtifacts;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\Collision;
use Tey\Mod\Placement\CollisionDiagnoser;
use Tey\Mod\Placement\CollisionKind;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Preset\PresetIssue;
use Tey\Mod\Preset\PresetValidator;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;

use function Laravel\Prompts\select;

/**
 * What every mod:* adapter shares: the preset and kind it generates, the
 * placement input (--in, one option per placement dimension, or the
 * "Group:Name" shorthand), placement,
 * relations and the collision check before writing.
 *
 * Everything a host package needs to build its own generator on top of an
 * adapter is a protected hook here:
 *
 *  - resolveLayout() / kindId(): which preset and kind this invocation is
 *    for, when the command is not bound through forKind() (a host may
 *    resolve them per invocation from its own configuration).
 *  - placementInput() / placementContext(): where the placement comes from.
 *    The default reads `--in`, the dimension options (`--module=`) or the
 *    shorthand prefix of the name argument; a host may derive it from its
 *    own options or prompts.
 *  - placementOptions(): the placement options the command adds (`--in`
 *    plus one per dimension the kind reads), or none for a host that
 *    provides placement its own way.
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
trait InteractsWithLayout
{
    /** Container key set while a mod:* command generates a related file through another one. */
    private const RELATED = 'mod.generating-related';

    /** @var array<string, string> group values the user settled on (case or near miss), by dimension */
    private array $modGroupValues = [];

    private ?CompiledLayout $modLayout = null;

    private ?ArtifactKind $modKind = null;

    /** @var list<string> the placement options this trait added to the definition */
    private array $modPlacementOptions = [];

    /** @var array<string, PresetIssue> dimension → why its option was left out */
    private array $modPlacementIssues = [];

    /** @internal */
    public function forKind(CompiledLayout $preset, ArtifactKind $kind): static
    {
        if ($kind->command === null) {
            throw new LogicException("File type [{$kind->id}] declares no command name.");
        }

        $this->modLayout = $preset;
        $this->modKind = $kind;

        $this->setName($kind->command);
        $this->setAliases($kind->aliases);
        $this->registerPlacementOptions();

        if ($this->fixedName() !== null) {
            $this->makeNameOptional();
        }

        return $this;
    }

    /**
     * Dimension options left out because the command already defines an
     * option of that name (or shortcut); the layout should rename them.
     *
     * @return list<PresetIssue>
     *
     * @internal
     */
    public function placementOptionIssues(): array
    {
        return array_values($this->modPlacementIssues);
    }

    /**
     * The preset this invocation generates against. Bound by forKind(), or
     * resolved by the host through resolveLayout().
     */
    protected function layout(): CompiledLayout
    {
        return $this->modLayout ??= $this->resolveLayout();
    }

    /**
     * The kind this invocation generates. Bound by forKind(), or the kind
     * named by kindId() in the preset.
     */
    protected function kind(): ArtifactKind
    {
        return $this->modKind ?? $this->layout()->kind($this->kindId());
    }

    /**
     * Hook: the preset when the command was not bound through forKind().
     */
    protected function resolveLayout(): CompiledLayout
    {
        throw new LogicException(static::class.' is not bound to a file type of the layout; bind it with forKind() or override resolveLayout().');
    }

    /**
     * Hook: the kind id when the command was not bound through forKind().
     * A host may decide it per invocation (from its input, say).
     */
    protected function kindId(): string
    {
        throw new LogicException(static::class.' is not bound to a file type of the layout; bind it with forKind() or override kindId().');
    }

    /**
     * Hook: the placement options the command adds, option name => the
     * dimension it sets (null for --in, which takes every dimension in
     * order). The default is --in plus, once the command is bound to a
     * kind, one option per dimension the kind reads (--module=), except
     * those that would shadow an option the command already defines.
     * A host that supplies placement its own way returns [].
     *
     * @return array<string, ?string>
     */
    protected function placementOptions(): array
    {
        $options = ['in' => null];

        if ($this->modKind === null) {
            return $options;
        }

        $names = $this->layout()->placementOptions();

        foreach ($this->kindDimensions() as $dimension) {
            if (! isset($this->modPlacementIssues[$dimension])) {
                $options[$names[$dimension]] = $dimension;
            }
        }

        return $options;
    }

    /**
     * Add the placement options to the definition, replacing the ones added
     * before (the set grows once the command is bound to a kind). A
     * dimension option the command already defines is left out and
     * recorded as an issue, so a native option is never shadowed.
     *
     * @internal
     */
    protected function registerPlacementOptions(): void
    {
        $definition = $this->getDefinition();
        $options = $definition->getOptions();

        foreach ($this->modPlacementOptions as $added) {
            unset($options[$added]);
        }

        $definition->setOptions(array_values($options));
        $this->modPlacementOptions = [];

        if ($this->modKind !== null) {
            $taken = ['in'];

            foreach ($definition->getOptions() as $name => $option) {
                $taken[] = $name;

                foreach (explode('|', (string) $option->getShortcut()) as $shortcut) {
                    if ($shortcut !== '') {
                        $taken[] = $shortcut;
                    }
                }
            }

            $this->modPlacementIssues = (new PresetValidator)->placementOptionCollisions($this->layout(), $this->kind(), $taken);
        }

        foreach ($this->placementOptions() as $option => $dimension) {
            if ($definition->hasOption($option)) {
                continue;
            }

            $definition->addOption(new InputOption($option, null, InputOption::VALUE_REQUIRED, $this->placementOptionDescription($dimension)));
            $this->modPlacementOptions[] = $option;
        }
    }

    /**
     * The dimensions the bound kind reads, in the layout's --in order.
     *
     * @return list<string>
     */
    private function kindDimensions(): array
    {
        $reads = $this->layout()->rule($this->kind()->id)->dimensions();

        return array_values(array_filter($this->layout()->dimensionNames(), static fn (string $name): bool => in_array($name, $reads, true)));
    }

    private function placementOptionDescription(?string $dimension): string
    {
        if ($dimension !== null) {
            foreach ($this->layout()->dimensions() as $declared) {
                if ($declared->name === $dimension && $declared->multi) {
                    return "Place in this {$dimension}, nested folders separated by \".\" or \"/\" (same as --in)";
                }
            }

            return "Place in this {$dimension} (same as --in)";
        }

        $dimensions = $this->modLayout?->dimensionNames() ?? [];

        if ($dimensions === []) {
            return 'Placement (this layout takes none)';
        }

        $multi = array_filter($this->layout()->dimensions(), static fn ($declared): bool => $declared->multi) !== [];

        return 'The '.implode(' and ', $dimensions).' to place in. Every value in the layout\'s order, separated by "/"'
            .($multi ? ' (a value spanning folders separates them with ".")' : '')
            .'; or prefix the name with "<value>:"';
    }

    /**
     * The raw name argument, before the shorthand prefix is split off.
     *
     * @internal
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
     *
     * @internal
     */
    protected function shorthand(): array
    {
        $raw = $this->rawNameInput();
        $colon = strpos($raw, ':');

        [$prefix, $name] = $colon === false ? [null, $raw] : [trim(substr($raw, 0, $colon)), trim(substr($raw, $colon + 1))];

        $dimensions = $this->layout()->dimensions();
        if ($prefix !== null && count($dimensions) === 1 && ! $dimensions[0]->multi && (str_contains($prefix, '/') || str_contains($prefix, '.'))) {
            $parts = explode('/', str_replace('.', '/', $prefix));
            $own = end($parts).':'.$name;
            $first = array_shift($parts);
            $subfolder = $first.':'.implode('/', [...$parts, $name]);
            $group = ucfirst($this->layout()->placementOptions()[$dimensions[0]->name]);
            $label = Str::plural($group);
            $interactive = $this->input->isInteractive()
                && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
            if (! $interactive) {
                throw GenerationRefused::because("{$label} don't nest. Use a ".strtolower($group)." of its own ({$own}), or a subfolder in the name ({$subfolder}).");
            }
            $answer = (string) select("{$label} don't nest. Which did you mean?", [$own, $subfolder]);
            $this->input->setArgument('name', $answer);

            return $this->shorthand();
        }

        return [$prefix, $name === '' ? ($this->fixedName() ?? '') : $name];
    }

    /**
     * The basename every class of the bound kind gets (slices' "Handler"), or null.
     *
     * @internal
     */
    protected function fixedName(): ?string
    {
        return $this->modKind?->namePolicy->kind === NamePolicyKind::Fixed ? $this->modKind->namePolicy->value : null;
    }

    /**
     * A kind with a fixed name needs no name argument.
     */
    private function makeNameOptional(): void
    {
        $definition = $this->getDefinition();

        $definition->setArguments(array_map(
            static fn (InputArgument $argument): InputArgument => $argument->getName() === 'name' && $argument->isRequired()
                ? new InputArgument('name', InputArgument::OPTIONAL, $argument->getDescription())
                : $argument,
            array_values($definition->getArguments()),
        ));
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
        $in = null;
        $given = [];

        foreach ($this->placementOptions() as $option => $dimension) {
            $value = $this->hasOption($option) ? $this->option($option) : null;
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

            if ($value === null) {
                continue;
            }

            if ($dimension === null) {
                $in = $value;
            } else {
                $given[$dimension] = [$option, $value];
            }
        }

        if ($prefix !== null && ($in !== null || $given !== [])) {
            [$option, $value] = $in !== null ? ['in', $in] : array_values($given)[0];

            throw GenerationRefused::because(sprintf(
                'Placement was given twice: as the prefix [%s:] of the name and as --%s=%s. Use one of them.',
                $prefix,
                $option,
                $value,
            ));
        }

        if ($in !== null && $given !== []) {
            throw InvalidPlacementOption::oneOf($in, array_map(static fn (array $pair): string => "--{$pair[0]}={$pair[1]}", array_values($given)));
        }

        if ($given !== []) {
            return $this->dimensionPlacement($given);
        }

        if ($prefix !== null && $this->layout()->dimensionNames() === []) {
            throw GenerationRefused::because(sprintf(
                'Layout [%s] takes no placement; drop the [%s:] prefix.',
                $this->layoutName(),
                $prefix,
            ));
        }

        if ($prefix === '') {
            throw GenerationRefused::because('The placement prefix before ":" is empty.');
        }

        return $prefix ?? $in;
    }

    /**
     * The dimension options as one placement in --in syntax: the values in
     * the layout's order, a multi-segment value's folders joined by ".".
     *
     * @param  array<string, array{0: string, 1: string}>  $given  dimension → [option, value]
     */
    private function dimensionPlacement(array $given): string
    {
        $values = [];
        $missing = null;

        foreach ($this->layout()->dimensions() as $dimension) {
            if (! isset($given[$dimension->name])) {
                $missing ??= $dimension->name;

                continue;
            }

            [$option, $value] = $given[$dimension->name];

            if ($missing !== null) {
                throw InvalidPlacementOption::skipped($option, $this->layout()->placementOptions()[$missing], $this->layout()->dimensionNames());
            }

            if ($dimension->multi) {
                $value = str_replace('/', '.', $value);
            } elseif (str_contains($value, '/') || str_contains($value, '.')) {
                throw InvalidPlacementOption::malformedValue("--{$option}={$value}", $value);
            }

            $values[] = $value;
        }

        return implode('/', $values);
    }

    /**
     * Hook: where the primary artifact is placed.
     */
    protected function placementContext(): PlacementContext
    {
        $context = PlacementContext::fromOption($this->placementInput() ?? '', $this->layout());

        foreach ($this->modGroupValues as $dimension => $value) {
            $context = $context->with($dimension, $value);
        }

        return $context;
    }

    /**
     * Hook: the layout's name for messages.
     *
     * @internal
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
     *
     * @internal
     */
    protected function resolveArtifact(string $kindId, string $name, PlacementContext $context, array $attributes = []): ResolvedArtifact
    {
        return (new PlacementResolver($this->layout()))->resolve(ArtifactRequest::for($kindId, $name, $context, $attributes));
    }

    /**
     * The class an option such as --model or --event names.
     *
     * A namespaced name (App\\Models\\Invoice, \\Illuminate\\...) is used as
     * given. A bare name is placed as that kind with this invocation's
     * placement, narrowed to the dimensions the kind's rule reads; a
     * slash-nested name is refused by placement with the --in hint unless the
     * kind accepts nested names.
     *
     * @internal
     */
    protected function placeSibling(string $kindId, string $name): string
    {
        $name = trim($name);

        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (! $this->layout()->hasKind($kindId)) {
            throw GenerationRefused::because("The layout has no [{$kindId}] file type to place [{$name}]; pass its fully qualified class name.");
        }

        $context = $this->placementContext()->only($this->layout()->rule($kindId)->dimensions());

        return (string) $this->resolveArtifact($kindId, $name, $context)->fqcn();
    }

    /**
     * Where an existing or planned class lives, when a preset rule owns it.
     *
     * @internal
     */
    protected function ownedPathOf(string $fqcn): ?string
    {
        $match = (new ReverseMapper($this->layout()))->fromClass($fqcn);

        return $match->isMatched() && $match->artifact !== null ? $match->artifact->path() : null;
    }

    /**
     * Whether a class exists: known to the autoloader, or written where the preset places it.
     *
     * @internal
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
     *
     * @internal
     */
    protected function generateOwnedClass(string $fqcn, string $kindId): void
    {
        $match = (new ReverseMapper($this->layout()))->fromClass($fqcn);
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
     *
     * @internal
     */
    protected function relationsTo(ResolvedArtifact $source, string $toKind, ?string $name = null, array $attributes = [], bool $required = true): array
    {
        $resolver = new RelationResolver($this->layout(), new PlacementResolver($this->layout()));
        $resolutions = [];

        foreach ($this->layout()->relationsFrom($source->kind->id) as $relation) {
            if ($relation->toKind !== $toKind || $relation->mode === RelationMode::None) {
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
     *
     * @internal
     */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $target = $resolution->target ?? throw new LogicException('Only resolved relations can be followed.');

        if ($resolution->mode() !== RelationMode::Generate) {
            $this->reportReference($target);

            return;
        }

        $command = $this->layout()->kind($target->kind->id)->command;

        if ($command === null) {
            throw GenerationRefused::because("File type [{$target->kind->id}] has no command to generate [{$target->describe()}].");
        }

        $depth = $this->laravel->bound(self::RELATED) ? (int) $this->laravel->make(self::RELATED) : 0;
        $this->laravel->instance(self::RELATED, $depth + 1);

        try {
            $exitCode = $this->call($command, [
                ...$this->argumentsFor($target),
                ...array_filter($arguments, static fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''),
            ]);
        } finally {
            $depth === 0 ? $this->laravel->forgetInstance(self::RELATED) : $this->laravel->instance(self::RELATED, $depth);
        }

        if ($exitCode !== 0) {
            throw GenerationRefused::because("Generating related {$target->kind->id} [{$target->describe()}] failed.");
        }
    }

    /**
     * Hook: the arguments that make a child command generate exactly the given artifact.
     *
     * The default passes the nested name and the placement through `--in`
     * (or through the shorthand prefix when the command adds no --in). A
     * host whose commands take placement differently overrides it.
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    protected function argumentsFor(ResolvedArtifact $target): array
    {
        $placement = $this->inOption($target->context);

        if ($placement === '') {
            return ['name' => $target->nestedName()];
        }

        if (! array_key_exists('in', $this->placementOptions())) {
            return ['name' => $placement.':'.$target->nestedName()];
        }

        return ['name' => $target->nestedName(), '--in' => $placement];
    }

    /**
     * The --in value that reproduces a placement context.
     *
     * @internal
     */
    protected function inOption(PlacementContext $context): string
    {
        $values = [];
        $gap = false;

        foreach ($this->layout()->dimensions() as $dimension) {
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

    /** @internal */
    protected function existingArtifacts(): ExistingArtifacts
    {
        return new ExistingArtifacts($this->laravel->basePath());
    }

    /**
     * @throws GenerationRefused
     *
     * @internal
     */
    protected function refuseCollisions(GenerationPlan $plan, bool $overwritePrimary): void
    {
        if ($this->collisionPolicy() === CollisionPolicy::Native) {
            return;
        }

        $collisions = $plan->collisions(new CollisionDiagnoser, $this->existingArtifacts(), $overwritePrimary);

        if ($collisions !== []) {
            throw GenerationRefused::collisions($collisions, nothingMissing: ! $overwritePrimary && $this->everyFileExists($plan, $collisions));
        }
    }

    /**
     * Whether every file the plan would write already exists, and no class
     * clashes with one elsewhere: then nothing new was held back.
     *
     * @param  list<Collision>  $collisions
     */
    private function everyFileExists(GenerationPlan $plan, array $collisions): bool
    {
        foreach ($collisions as $collision) {
            if ($collision->kind !== CollisionKind::Path) {
                return false;
            }
        }

        foreach ([$plan->primary, ...$plan->generated()] as $artifact) {
            if (! is_file($this->existingArtifacts()->absolute($artifact->path()))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook: how a refusal is reported; returns the exit code.
     */
    protected function reportRefusal(ModException $exception): int
    {
        foreach (explode(PHP_EOL, $this->refusalMessage($exception)) as $line) {
            $this->components->error($line);
        }

        return $exception instanceof GenerationRefused && $exception->nothingMissing ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A refusal in this command's own words: a missing or unused placement
     * value of the kind it generates names the command and its options.
     *
     * @internal
     */
    protected function refusalMessage(ModException $exception): string
    {
        $placement = $exception instanceof MissingDimension || $exception instanceof DimensionNotApplicable;

        if (! $placement || $this->modKind === null || $exception->kindId !== $this->kind()->id) {
            return $exception->getMessage();
        }

        $command = (string) $this->getName();
        $dimension = $exception->dimension;

        if ($exception instanceof DimensionNotApplicable) {
            return "{$command} does not use a {$dimension} in this layout. Leave the {$dimension} out.";
        }

        // The fix names every value the path needs, in --in order: slices
        // need <feature>/<slice>, not just the missing slice.
        $needed = $this->requiredDimensions();

        if (! in_array($dimension, $needed, true)) {
            $needed = [$dimension];
        }

        $placement = implode('/', array_map(static fn (string $name): string => "<{$name}>", $needed));
        $options = [];

        foreach ($needed as $name) {
            $option = array_search($name, $this->placementOptions(), true);

            if (! is_string($option)) {
                $options = [];

                break;
            }

            $options[] = "--{$option}=<{$name}>";
        }

        $ways = $options === [] ? "--in={$placement}" : implode(' ', $options).", --in={$placement}";
        $name = $this->shorthand()[1];

        $article = preg_match('/^[aeiou]/i', $dimension) === 1 ? 'an' : 'a';

        return "{$command} needs {$article} {$dimension}. Pass {$ways}, or prefix the name: {$placement}:".($name !== '' ? $name : 'Name').'.';
    }

    /**
     * The dimensions the bound kind's path cannot do without, in --in order.
     *
     * @return list<string>
     */
    private function requiredDimensions(): array
    {
        $rule = $this->layout()->rule($this->kind()->id);

        if (! $rule instanceof TemplateRule) {
            return [];
        }

        $required = [];

        foreach ($rule->segments() as $segment) {
            if ($segment->dimension !== null && $segment->required) {
                $required[] = $segment->dimension;
            }
        }

        return array_values(array_filter($this->kindDimensions(), static fn (string $name): bool => in_array($name, $required, true)));
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
