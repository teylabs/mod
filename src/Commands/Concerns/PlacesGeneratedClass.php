<?php

namespace Tey\Mod\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Commands\GenericClassCommand;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\BaseWriter;
use Tey\Mod\Generation\ClassMembers;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubChoice;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Generation\StubSelection;
use Tey\Mod\Relation\RelationResolution;
use Tey\Mod\Support\ComposerJson;
use Tey\Mod\Support\Path;

use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

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
 *    normalize it further (studly case, say) by overriding it.
 *  - stubDefinition(): the Stub the kind's classes come from (a package's
 *    registered stub, else the layout's); its variants and base are applied
 *    when the plan is resolved.
 *
 * @internal
 */
trait PlacesGeneratedClass
{
    use InteractsWithLayout;

    private ?GenerationPlan $plan = null;

    private ?StubChoice $modStub = null;

    private bool $modStubPrepared = false;

    /** The new-group notice, held until the class is written. */
    private ?string $modNewGroup = null;

    /** @internal */
    public static function supports(ArtifactKind $kind): bool
    {
        return $kind->isClass();
    }

    /**
     * Add --in once the native definition is built, whichever way the command
     * declares it: $name and getOptions(), or $signature (Laravel 13.24+).
     * Not configure(), which Symfony Console 7 leaves untyped and 8 declares
     * void, so no override of it could match both for subclasses.
     *
     * @return void
     *
     * @internal
     */
    protected function specifyParameters()
    {
        parent::specifyParameters();

        $this->registerPlacementOptions();
    }

    /**
     * @return void
     *
     * @internal
     */
    protected function configureUsingFluentDefinition()
    {
        parent::configureUsingFluentDefinition();

        $this->registerPlacementOptions();
    }

    /**
     * Resolve and check the plan, then let the native command run with it.
     *
     * @internal
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run') && $this->scaffoldExecution() === null) {
            return $this->previewGeneration(fn (): int => $this->execute($input, $output));
        }
        $previous = $this->plan;
        $this->plan = null;
        $this->modStub = null;
        $this->modStubPrepared = false;
        $this->modGroupValues = [];
        $this->modNewGroup = null;
        $exitCode = self::FAILURE;

        try {
            $this->noteUnusedName();

            $scaffold = $this->scaffoldExecution();
            if ($scaffold?->planning) {
                if ($this->isReservedName($this->getNameInput())) {
                    throw GenerationRefused::because('The name "'.$this->getNameInput().'" is reserved by PHP. Nothing was written.');
                }
                [$preview, $notice] = $this->settleGroups($this->plan());
                if ($notice !== null && ! in_array($notice, $scaffold->groupNotices, true)) {
                    $scaffold->groupNotices[$preview->primary->path()] = $notice;
                }
                $this->plan = $preview;
                $defaultStub = $this->getStub();
                $this->generateScaffoldRelations();
                $scaffold->collect($preview, $defaultStub);

                return $exitCode = self::SUCCESS;
            }

            if ($this->plansEagerly()) {
                $this->resolvePlan();
                $this->warnIfNotAutoloaded();
            }

            if ($scaffold !== null && $this->plan !== null && $scaffold->keeps($this->plan->primary)) {
                if (! in_array($this->plan->primary->path(), $scaffold->silentKeep, true)) {
                    $this->components->info('Kept '.$this->plan->primary->path().'.');
                }
                $this->generateScaffoldRelations();

                return $exitCode = self::SUCCESS;
            }

            $exitCode = parent::execute($input, $output);

            // A host handle() that writes the class itself: announce the group once it exists.
            if ($exitCode === self::SUCCESS && $this->plan !== null && is_file($this->existingArtifacts()->absolute($this->plan->primary->path()))) {
                $this->announceNewGroup();
            }

            return $exitCode;
        } catch (ModException $exception) {
            return $exitCode = $this->reportRefusal($exception);
        } finally {
            $this->modNewGroup = null;
            $plan = $this->currentPlan();

            if ($plan !== null && ! $this->scaffoldExecution()?->planning) {
                $this->afterGeneration($plan, $exitCode);
            }

            $this->plan = $previous;
        }
    }

    private function warnIfNotAutoloaded(): void
    {
        $primary = $this->plan?->primary;
        if ($primary === null || ! $primary->kind->isClass()) {
            return;
        }
        $root = $this->layout()->rule($primary->kind->id)->root();
        $composer = new ComposerJson($this->laravel->basePath('composer.json'));
        if ($composer->covers((string) $root->namespace, $root->path)) {
            return;
        }
        $this->components->warn("{$root->path} isn't autoloaded yet. Run php artisan mod:autoload.");
    }

    /**
     * Settle the group folders the plan walks into: a value that differs from
     * the one existing group only by case uses that group; several such groups,
     * or a near miss, are a question when interactive; anything else is a new
     * group, reported once the plan stands.
     *
     * @return array{0: GenerationPlan, 1: ?string} the plan and the new-group notice
     *
     * @internal
     */
    protected function settleGroups(GenerationPlan $plan): array
    {
        $groups = $this->laravel->make(GroupFolders::class, ['basePath' => $this->laravel->basePath()]);
        $interactive = $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
        $templated = isset($this->layout()->templates()[$this->kind()->id]);

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
            } elseif ($kind === 'near' && ! $interactive && $this->scaffoldExecution()?->groupFlag !== null) {
                $flag = $this->scaffoldExecution()->groupFlag;
                throw GenerationRefused::because($this->getName().": {$value} doesn't exist. Did you mean {$suggestions[0]}? Pass {$flag}={$suggestions[0]}. Nothing was written.");
            } elseif ($kind === 'near' && $interactive && ($templated || $this->scaffoldExecution()?->groupFlag !== null)) {
                $choice = (string) suggest("{$value} doesn't exist. Did you mean {$suggestions[0]}?", $suggestions, default: $suggestions[0], required: true);
                if ($choice === $value) {
                    $choice = null;
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

                $scaffold = $this->scaffoldExecution();
                if ($scaffold?->planning) {
                    $key = $dimension.':'.$value;
                    if (in_array($key, $scaffold->newGroups, true)) {
                        return [$plan, null];
                    }
                    $scaffold->newGroups[] = $key;
                }

                return [$plan, "Created new {$dimension} {$value}".($templated && $kind === 'near' ? ' (did you mean '.$suggestions[0].'?)' : ($existing === [] ? '' : ' (existing: '.implode(', ', $existing).')')).'.'];
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
     *
     * @api
     */
    protected function currentPlan(): ?GenerationPlan
    {
        return $this->plan;
    }

    /**
     * Hook: whether the plan is resolved before the native handle() runs.
     *
     * @api
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
     *
     * @api
     */
    protected function resolvePlan(): GenerationPlan
    {
        if ($this->plan !== null) {
            return $this->plan;
        }

        $candidate = $this->plan();
        $accepted = $this->scaffoldExecution()?->plans[$candidate->primary->path()] ?? null;
        [$plan, $newGroup] = $accepted !== null ? [$accepted, $this->scaffoldExecution()?->groupNotices[$accepted->primary->path()] ?? null] : $this->settleGroups($candidate);
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

        // Announced once the class is written: the native command may still refuse (an unknown guard, say).
        $this->modNewGroup = $newGroup;
        $this->plan = $plan;
        $this->beforeGeneration($plan);

        return $plan;
    }

    /**
     * The native generator calls this with the built class just before writing
     * it, after every check of its own: the moment a new group really appears.
     *
     * @param  string  $stub
     * @return string
     *
     * @internal
     */
    protected function sortImports($stub)
    {
        $this->announceNewGroup();

        $stub = $this->scaffoldExecution()?->replace($stub, $this->primary()) ?? $stub;

        return parent::sortImports($stub);
    }

    private function announceNewGroup(): void
    {
        if ($this->modNewGroup !== null) {
            $this->components->info($this->modNewGroup);
            $this->modNewGroup = null;
        }
    }

    /**
     * Hook: before the native generator writes, with the resolved plan.
     *
     * @api
     */
    protected function beforeGeneration(GenerationPlan $plan): void {}

    /**
     * Hook: after the native generator ran (or was refused), with the plan and the exit code.
     *
     * @api
     */
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void {}

    /** @api */
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
    /** generate companions even when an existing primary is kept @internal */
    protected function generateScaffoldRelations(): void
    {
        foreach (($this->currentPlan() ?? throw new \LogicException('A plan is required to generate companions.'))->relations as $relation) {
            $this->followRelation($relation);
        }
    }

    /**
     * @return list<RelationResolution>
     *
     * @api
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
     *
     * @api
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
     *
     * @internal
     */
    protected function getNameInput()
    {
        return $this->shorthand()[1];
    }

    /**
     * @param  string  $name
     * @return string
     *
     * @internal
     */
    protected function qualifyClass($name)
    {
        if (trim($name) === $this->getNameInput()) {
            return (string) $this->primary()->fqcn();
        }

        return parent::qualifyClass($name);
    }

    /**
     * @param  string  $rawName
     * @return bool
     *
     * @internal
     */
    protected function alreadyExists($rawName)
    {
        $scope = $this->scaffoldExecution();
        if ($scope?->force && isset($scope->plans[$this->primary()->path()])) {
            return false;
        }

        return parent::alreadyExists($rawName);
    }

    /**
     * @return string
     *
     * @internal
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
     *
     * @api
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
     *
     * @internal
     */
    protected function modStubFile(): ?string
    {
        $variant = $this->scaffoldExecution()?->variants[$this->primary()->path()] ?? null;
        if ($variant !== null) {
            return $variant;
        }
        $choice = $this->prepareStub();

        return $this->laravel->make(StubRegistry::class)->selectedFile($this->kind()->id, $this->laravel->basePath(), $choice?->file);
    }

    /**
     * Untyped, as Laravel declares it, so subclasses can override it either way.
     *
     * @return string
     *
     * @internal
     */
    protected function getStub()
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
     *
     * @internal
     */
    protected function replaceClass($stub, $name)
    {
        $stub = $this->scaffoldExecution()?->replace($stub, $this->primary()) ?? $stub;
        $stub = parent::replaceClass($stub, $name);
        $base = $this->modStub?->base;
        $short = $base !== null ? class_basename($base) : '';
        // "\n", not PHP_EOL: generated PHP is LF on every OS, like the stubs.
        $rendered = str_replace(['{{ base }}', '{{base}}', '{{ baseClass }}', '{{baseClass}}', '{{ baseImport }}', '{{baseImport}}'], [$base ?? '', $base ?? '', $short, $short, '', ''], $stub);
        $import = $base !== null && ! (new ClassMembers($rendered))->hasImport($base) ? "\nuse {$base};\n" : '';
        $extends = $base !== null ? ' extends '.$short : '';

        return str_replace(
            ['{{ base }}', '{{base}}', '{{ baseClass }}', '{{baseClass}}', '{{ baseImport }}', '{{baseImport}}', '{{ extends }}', '{{extends}}'],
            [$base ?? '', $base ?? '', $short, $short, $import, $import, $extends, $extends],
            $stub,
        );
    }

    /** Read-only provenance for mod:list; never prepares or writes a base. @internal */
    public function stubSelection(): StubSelection
    {
        $config = $this->laravel->make('config');
        $configured = $config->get('mod.bases.'.$this->kind()->id);

        return $this->laravel->make(StubRegistry::class)->select(
            $this->kind()->id, $this->layout()->stub($this->kind()->id), $this->laravel->basePath(),
            $this->laravel->make(PackageDetector::class), static fn (string $key): mixed => $config->get($key),
            is_string($configured) ? $configured : null,
            $this->layout()->templates()[$this->kind()->id]['source'] ?? null,
            ! $this instanceof GenericClassCommand,
            $this->stubDefinition(),
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

        $choice = $this->stubSelection()->choice;
        if ($choice === null) {
            return null;
        }

        $scaffold = $this->scaffoldExecution();
        if ($scaffold?->planning) {
            if ($choice->generatedBase !== null) {
                $location = $this->laravel->make(BaseWriter::class)->locate($choice->generatedBase, $this->layout(), $this->kind()->id);
                $basePath = $this->existingArtifacts()->absolute($location['path']);
                if (! is_file($basePath) && ! class_exists($location['fqcn'])) {
                    if (! is_file($choice->generatedBase->stub)) {
                        throw GenerationRefused::because('Base template ['.$choice->generatedBase->stub.'] does not exist. Nothing was written.');
                    }
                    $scaffold->bases[$this->primary()->path()][] = new ResolvedArtifact(
                        $this->kind(), $this->primary()->context, $choice->generatedBase->name,
                        new ClassIdentity(substr($location['fqcn'], 0, (int) strrpos($location['fqcn'], '\\')), $choice->generatedBase->name, $location['path']),
                    );
                }
            }

            return $this->modStub = $choice;
        }

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
