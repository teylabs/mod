<?php

namespace Tey\Mod\Commands;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Database\Console\Migrations\TableGuesser;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Commands\Concerns\InteractsWithLayout;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\StubSelection;
use Tey\Mod\Support\Path;

/**
 * Native make:migration, placed by the preset.
 *
 * The timestamp comes from the native creator's clock for the resolved
 * directory, feeds the timestamped name policy, and is pinned so the native
 * creator writes exactly the resolved file. The name argument accepts the
 * "Group:create_x_table" shorthand like every adapter.
 *
 * Hooks: plansEagerly()/resolvePlan() plan before the native handle() or from
 * inside a host's own handle(); nativePathAllowed() lets a host honour
 * --path/--realpath natively instead of refusing them; beforeGeneration()/
 * afterGeneration() run around the native write.
 *
 * @api
 */
class MigrationCommand extends MigrateMakeCommand implements GeneratorAdapter
{
    use InteractsWithLayout {
        rawNameInput as traitRawNameInput;
    }

    private ?GenerationPlan $plan = null;

    /** The name argument as given, kept while the native handle() sees it without the shorthand prefix. */
    private ?string $rawName = null;

    private readonly ModMigrationCreator $modCreator;

    private readonly bool $applicationCreator;

    /** @api */
    public function __construct(MigrationCreator $creator, Composer $composer)
    {
        $this->applicationCreator = ! $creator instanceof ModMigrationCreator && get_class($creator) !== MigrationCreator::class;
        $this->modCreator = $creator instanceof ModMigrationCreator ? $creator : ModMigrationCreator::fromNative($creator);
        parent::__construct($this->applicationCreator ? $creator : $this->modCreator, $composer);
    }

    /** The default native migration stub, shared with mod:list. @internal */
    public function stubSelection(?string $table = null, bool $create = false): StubSelection
    {
        return $this->modCreator->stubSelection($table, $create);
    }

    /** @internal */
    public static function supports(ArtifactKind $kind): bool
    {
        return ! $kind->isClass() && $kind->namePolicy->kind === NamePolicyKind::Timestamped;
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
     * Resolve and check the migration, then let the native command write it with the pinned timestamp.
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
        $exitCode = self::FAILURE;

        try {
            $nativePath = $this->input->getOption('path') !== null || (bool) $this->input->getOption('realpath');

            if ($nativePath && ! $this->nativePathAllowed()) {
                throw GenerationRefused::because('mod:* places migrations from the layout; use --in instead of --path/--realpath.');
            }

            if ($nativePath || $this->applicationCreator) {
                return $exitCode = parent::execute($input, $output);
            }

            // The native handle() reads the raw argument; hand it the name without the shorthand prefix
            // and keep the original for the placement shorthand.
            $this->rawName = $this->traitRawNameInput();
            $this->input->setArgument('name', $this->getNameInput());

            if ($this->plansEagerly()) {
                $this->resolvePlan();
            }

            if ($this->scaffoldExecution()?->planning) {
                $this->scaffoldExecution()->collect($this->resolvePlan(), $this->migrationStubFile());

                return $exitCode = self::SUCCESS;
            }
            $resolved = $this->currentPlan();
            if ($resolved !== null && $this->scaffoldExecution()?->keeps($resolved->primary)) {
                $this->components->info('Kept '.$resolved->primary->path().'.');

                return $exitCode = self::SUCCESS;
            }

            return $exitCode = parent::execute($input, $output);
        } catch (ModException $exception) {
            return $exitCode = $this->reportRefusal($exception);
        } finally {
            $this->modCreator->pin(null);
            $this->modCreator->useStub(null);
            $plan = $this->currentPlan();

            if ($plan !== null && ! $this->scaffoldExecution()?->planning) {
                $this->afterGeneration($plan, $exitCode);
            }

            $this->plan = $previous;
            $this->rawName = null;
        }
    }

    /** @internal */
    protected function rawNameInput(): string
    {
        return $this->rawName ?? $this->traitRawNameInput();
    }

    /**
     * Hook: whether the migration is planned before the native handle() runs.
     * Return false and call resolvePlan() from your own handle() to plan after
     * your own preparation (a prompt, say).
     *
     * @api
     */
    protected function plansEagerly(): bool
    {
        return true;
    }

    /**
     * Resolve the migration (directory from the layout, timestamp from the
     * native clock for that directory), refuse collisions per the collision
     * policy, pin the timestamp and run beforeGeneration(). Idempotent within
     * one invocation.
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

        $name = Str::snake($this->getNameInput());
        $context = $this->placementContext();

        // The directory never depends on the timestamp; resolve it first to read the native clock there.
        $directory = dirname($this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => '0000_00_00_000000'])->path());
        $absoluteDirectory = $this->existingArtifacts()->absolute($directory);
        $timestamp = $this->modCreator->datePrefixFor($absoluteDirectory);
        // A migration's identity is its name within this directory, regardless of its clock prefix.
        foreach (glob(Path::join($absoluteDirectory, '*_'.$name.'.php')) ?: [] as $file) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_'.preg_quote($name, '/').'\.php$/', basename($file), $match) === 1) {
                $timestamp = $match[1];
                break;
            }
        }

        $candidate = $this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => $timestamp]);
        $plan = $this->scaffoldExecution()?->accepted($candidate) ?? new GenerationPlan($candidate);
        $timestamp = substr(basename($plan->primary->path()), 0, 17);
        $this->refuseCollisions($plan, false);
        $this->plan = $plan;
        $this->modCreator->pin($timestamp);
        if (! $this->scaffoldExecution()?->planning) {
            $this->beforeGeneration($plan);
        }

        return $plan;
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
     * Hook: whether --path/--realpath are honoured natively (bypassing the layout) instead of refused.
     *
     * @api
     */
    protected function nativePathAllowed(): bool
    {
        return false;
    }

    /**
     * Hook: before the native creator writes, with the resolved migration.
     *
     *
     * @api
     */
    protected function beforeGeneration(GenerationPlan $plan): void {}

    /**
     * Hook: after the native creator ran (or was refused).
     *
     *
     * @api
     */
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void {}

    /**
     * The migration name without its placement shorthand prefix.
     *
     * @return string
     *
     * @internal
     */
    protected function getNameInput()
    {
        return trim($this->shorthand()[1]);
    }

    /**
     * @return string
     *
     * @internal
     */
    protected function getMigrationPath()
    {
        if ($this->plan === null) {
            return parent::getMigrationPath();
        }

        return dirname($this->existingArtifacts()->absolute($this->plan->primary->path()));
    }

    private function migrationStubFile(?string $table = null, ?bool $create = null): string
    {
        $primary = $this->resolvePlan()->primary;
        $variant = $this->scaffoldExecution()?->variants[$primary->path()] ?? null;
        if ($variant !== null) {
            return $variant;
        }
        if ($create === null) {
            $tableOption = $this->option('table');
            $table = is_string($tableOption) ? $tableOption : null;
            $createOption = $this->option('create');
            $create = (bool) $createOption;
            if (! $table && is_string($createOption) && $createOption !== '') {
                $table = $createOption;
                $create = true;
            }
            if (! $table) {
                [$table, $create] = TableGuesser::guess(Str::snake($this->getNameInput()));
            }
        }

        return $this->stubSelection($table ?: null, (bool) $create)->file
            ?? throw new \LogicException('The migration creator must select a stub file.');
    }

    /**
     * @param  string  $name
     * @param  string|null  $table
     * @param  bool  $create
     * @return void
     *
     * @internal
     */
    protected function writeMigration($name, $table, $create)
    {
        if ($this->plan !== null) {
            $source = $this->modCreator->getFilesystem()->get($this->migrationStubFile($table, (bool) $create));
            $source = $this->scaffoldExecution()?->replace($source, $this->plan->primary) ?? $source;
            $this->modCreator->useStub($source);
        }
        $file = $this->creator->create($name, $this->getMigrationPath(), $table, $create);

        if ($this->plan !== null && ! Path::same($file, $this->existingArtifacts()->absolute($this->plan->primary->path()))) {
            throw GenerationRefused::because("The native creator wrote [{$file}], not the resolved [{$this->plan->primary->path()}].");
        }

        $this->components->info(sprintf('Migration [%s] created successfully.', windows_os() ? str_replace('/', '\\', $file) : $file));
    }
}
