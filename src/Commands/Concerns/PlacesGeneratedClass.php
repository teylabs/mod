<?php

namespace Tey\Mod\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\BaseWriter;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubChoice;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Support\Path;

use function Laravel\Prompts\select;

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
 *  - stubDefinition(): the Stub the kind's classes come from (a package's
 *    registered stub, else the layout's); its variants and base are applied
 *    when the plan is resolved.
 */
trait PlacesGeneratedClass
{
    use InteractsWithLayout;

    private ?GenerationPlan $plan = null;

    private ?StubChoice $modStub = null;

    private bool $modStubPrepared = false;

    public static function supports(ArtifactKind $kind): bool
    {
        return $kind->isClass();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->registerPlacementOptions();
    }

    /**
     * Resolve and check the plan, then let the native command run with it.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->plan;
        $this->plan = null;
        $this->modStub = null;
        $this->modStubPrepared = false;
        $this->modGroupValues = [];
        $exitCode = self::FAILURE;

        try {
            $this->noteUnusedName();

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
     * Settle the group folders the plan walks into: a value that differs from
     * the one existing group only by case uses that group; several such groups,
     * or a near miss, are a question when interactive; anything else is a new
     * group, reported once the plan stands.
     *
     * @return array{0: GenerationPlan, 1: ?string} the plan and the new-group notice
     */
    private function settleGroups(GenerationPlan $plan): array
    {
        $groups = $this->laravel->make(GroupFolders::class, ['basePath' => $this->laravel->basePath()]);
        $interactive = $this->input->isInteractive();

        for ($level = 0; $level < 64; $level++) {
            $finding = $groups->inspect($this->layout(), $plan->primary);

            if ($finding === null) {
                return [$plan, null];
            }

            ['kind' => $kind, 'dimension' => $dimension, 'value' => $value, 'suggestions' => $suggestions] = $finding;
            $group = ucfirst($dimension);
            $choice = null;

            if ($kind === 'case') {
                $choice = $suggestions[0];
                $this->components->info("Using existing {$dimension} {$choice} (you typed {$value}).");
            } elseif ($kind === 'cases') {
                if (! $interactive) {
                    throw GenerationRefused::because("{$group} [{$value}] doesn't exist; did you mean [".implode('] or [', $suggestions).']?');
                }

                $choice = (string) select("{$group} [{$value}] doesn't exist. Which one did you mean?", [...$suggestions, 'Cancel'], $suggestions[0]);

                if ($choice === 'Cancel') {
                    throw GenerationRefused::because('Cancelled; nothing was written.');
                }
            } elseif ($kind === 'near' && $interactive) {
                $create = "Create new {$dimension} {$value}";
                $answer = (string) select("{$group} [{$value}] doesn't exist. Did you mean an existing one?", [...$suggestions, $create], $suggestions[0]);
                $choice = $answer === $create ? null : $answer;
            }

            if ($choice === null) {
                $existing = $finding['existing'];

                // A related file generated by another mod:* command: that command reported the group.
                if ($this->laravel->bound(self::RELATED)) {
                    return [$plan, null];
                }

                return [$plan, "Created new {$dimension} {$value}".($existing === [] ? '' : ' (existing: '.implode(', ', $existing).')').'.'];
            }

            $this->modGroupValues[$dimension] = $choice;
            $plan = $this->plan();
        }

        return [$plan, null];
    }

    /**
     * A kind with a fixed name ignores the name it is given: say so.
     */
    private function noteUnusedName(): void
    {
        $fixed = $this->fixedName();
        $raw = $this->rawNameInput();
        $given = $raw === '' ? '' : $this->shorthand()[1];

        if ($fixed !== null && $given !== '' && $given !== $fixed) {
            $this->components->info("{$this->getName()} always writes {$fixed}.php; the name [{$given}] is not used.");
        }
    }

    /**
     * The plan of the running invocation, once resolved.
     *
     * @internal
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

        [$plan, $newGroup] = $this->settleGroups($this->plan());
        // Read through the input itself: not every adapter's native command declares --force.
        $force = $this->input->hasOption('force') && (bool) $this->input->getOption('force');
        // Let native placement handle its own duplicate; custom placement keeps
        // the whole-plan refusal and reports the same successful exit code.
        $this->plan = $plan;
        try {
            $nativeDuplicate = ! $force
                && Path::same($this->existingArtifacts()->absolute($plan->primary->path()), parent::getPath(parent::qualifyClass($this->getNameInput())))
                && $this->alreadyExists($this->getNameInput());
        } finally {
            $this->plan = null;
        }

        if (! $nativeDuplicate) {
            $this->refuseCollisions($plan, $force);
            // Bases and stub variants only once the class will really be written.
            $this->prepareStub();
        }

        if ($newGroup !== null) {
            $this->components->info($newGroup);
        }

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

    /** @internal */
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
     *
     * @internal
     */
    protected function plannedRelations(ResolvedArtifact $primary): array
    {
        return [];
    }

    /** @internal */
    protected function primary(): ResolvedArtifact
    {
        return $this->plan !== null ? $this->plan->primary : $this->resolveArtifact($this->kind()->id, $this->getNameInput(), $this->placementContext());
    }

    /**
     * The planned relation with the given id, if this invocation follows it.
     *
     * @internal
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
     *
     * @internal
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

    /**
     * Hook: the Stub the kind's classes are generated from: the one a
     * package registered for the kind (Mod::stubs()), else the layout's,
     * else the starter for a kind of this id.
     */
    protected function stubDefinition(): ?Stub
    {
        $kind = $this->kind()->id;

        return $this->laravel->make(StubRegistry::class)->resolve($kind, $this->layout()->stub($kind));
    }

    /**
     * The stub file to render: the application's published stubs/mod.<kind>.stub,
     * else the file the kind's Stub chose, else null for the generator's own.
     *
     * @internal
     */
    protected function modStubFile(): ?string
    {
        $choice = $this->prepareStub();
        $published = $this->laravel->basePath('stubs/mod.'.$this->kind()->id.'.stub');

        return is_file($published) ? $published : $choice?->file;
    }

    protected function getStub(): string
    {
        return $this->modStubFile() ?? parent::getStub();
    }

    /**
     * Fill the base placeholders with the base the stub chose: {{ base }}
     * (full name), {{ baseClass }} (short name), {{ baseImport }} (a use
     * line) and {{ extends }} (" extends Base"); the last two are empty
     * when there is no base.
     *
     * @param  string  $stub
     * @param  string  $name
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $stub = parent::replaceClass($stub, $name);
        $base = $this->modStub?->base;
        $short = $base !== null ? class_basename($base) : '';
        // "\n", not PHP_EOL: generated PHP is LF on every OS, like the stubs.
        $import = $base !== null ? "\nuse {$base};\n" : '';
        $extends = $base !== null ? ' extends '.$short : '';

        return str_replace(
            ['{{ base }}', '{{base}}', '{{ baseClass }}', '{{baseClass}}', '{{ baseImport }}', '{{baseImport}}', '{{ extends }}', '{{extends}}'],
            [$base ?? '', $base ?? '', $short, $short, $import, $import, $extends, $extends],
            $stub,
        );
    }

    /**
     * Resolve the kind's Stub once per invocation: say which branch applies,
     * and write the generated base on first use.
     */
    private function prepareStub(): ?StubChoice
    {
        if ($this->modStubPrepared) {
            return $this->modStub;
        }

        $this->modStubPrepared = true;
        $stub = $this->stubDefinition();

        if ($stub === null) {
            return null;
        }

        $config = $this->laravel->make('config');
        $configured = $config->get('mod.bases.'.$this->kind()->id);
        $choice = $stub->choose(
            $this->laravel->make(PackageDetector::class),
            static fn (string $key): mixed => $config->get($key),
            is_string($configured) ? $configured : null,
        );

        if ($choice->generatedBase !== null) {
            $choice = $choice->withBase($this->ensureBase($choice->generatedBase));
        } elseif ($choice->message !== null) {
            $this->components->info($choice->message);
        }

        return $this->modStub = $choice;
    }

    /**
     * The generated base's class, written first when it does not exist yet.
     * An existing base is never overwritten, not even with --force.
     */
    private function ensureBase(GeneratedBase $base): string
    {
        $writer = $this->laravel->make(BaseWriter::class);
        $location = $writer->locate($base, $this->layout(), $this->kind()->id);

        if ($writer->ensure($base, $location)) {
            $this->components->info("Created base class {$location['fqcn']} [{$location['path']}].");
        }

        return $location['fqcn'];
    }
}
