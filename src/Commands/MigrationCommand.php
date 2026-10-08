<?php

namespace Tey\Mod\Commands;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Commands\Concerns\InteractsWithPreset;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\ModMigrationCreator;

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
 */
class MigrationCommand extends MigrateMakeCommand implements GeneratorAdapter
{
    use InteractsWithPreset {
        rawNameInput as traitRawNameInput;
    }

    private ?GenerationPlan $plan = null;

    /** The name argument as given, kept while the native handle() sees it without the shorthand prefix. */
    private ?string $rawName = null;

    public function __construct(private readonly ModMigrationCreator $modCreator, Composer $composer)
    {
        parent::__construct($modCreator, $composer);
    }

    public static function supports(ArtifactKind $kind): bool
    {
        return ! $kind->isClass() && $kind->namePolicy->kind === NamePolicyKind::Timestamped;
    }

    protected function configure(): void
    {
        parent::configure();

        $this->registerPlacementOptions();
    }

    /**
     * Resolve and check the migration, then let the native command write it with the pinned timestamp.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->plan;
        $this->plan = null;
        $exitCode = self::FAILURE;

        try {
            $nativePath = $this->input->getOption('path') !== null || (bool) $this->input->getOption('realpath');

            if ($nativePath && ! $this->nativePathAllowed()) {
                throw GenerationRefused::because('mod:* places migrations from the layout; use --in instead of --path/--realpath.');
            }

            if ($nativePath) {
                return $exitCode = parent::execute($input, $output);
            }

            // The native handle() reads the raw argument; hand it the name without the shorthand prefix
            // and keep the original for the placement shorthand.
            $this->rawName = $this->traitRawNameInput();
            $this->input->setArgument('name', $this->getNameInput());

            if ($this->plansEagerly()) {
                $this->resolvePlan();
            }

            return $exitCode = parent::execute($input, $output);
        } catch (ModException $exception) {
            return $exitCode = $this->reportRefusal($exception);
        } finally {
            $this->modCreator->pin(null);
            $plan = $this->currentPlan();

            if ($plan !== null) {
                $this->afterGeneration($plan, $exitCode);
            }

            $this->plan = $previous;
            $this->rawName = null;
        }
    }

    protected function rawNameInput(): string
    {
        return $this->rawName ?? $this->traitRawNameInput();
    }

    /**
     * Hook: whether the migration is planned before the native handle() runs.
     * Return false and call resolvePlan() from your own handle() to plan after
     * your own preparation (a prompt, say).
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
        $timestamp = $this->modCreator->datePrefixFor($this->existingArtifacts()->absolute($directory));

        $plan = new GenerationPlan($this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => $timestamp]));
        $this->refuseCollisions($plan, false);
        $this->plan = $plan;
        $this->modCreator->pin($timestamp);
        $this->beforeGeneration($plan);

        return $plan;
    }

    /**
     * The plan of the running invocation, once resolved.
     */
    protected function currentPlan(): ?GenerationPlan
    {
        return $this->plan;
    }

    /**
     * Hook: whether --path/--realpath are honoured natively (bypassing the layout) instead of refused.
     */
    protected function nativePathAllowed(): bool
    {
        return false;
    }

    /**
     * Hook: before the native creator writes, with the resolved migration.
     */
    protected function beforeGeneration(GenerationPlan $plan): void {}

    /**
     * Hook: after the native creator ran (or was refused).
     */
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void {}

    /**
     * The migration name without its placement shorthand prefix.
     *
     * @return string
     */
    protected function getNameInput()
    {
        return trim($this->shorthand()[1]);
    }

    /**
     * @return string
     */
    protected function getMigrationPath()
    {
        if ($this->plan === null) {
            return parent::getMigrationPath();
        }

        return dirname($this->existingArtifacts()->absolute($this->plan->primary->path()));
    }

    /**
     * @param  string  $name
     * @param  string|null  $table
     * @param  bool  $create
     * @return void
     */
    protected function writeMigration($name, $table, $create)
    {
        $file = $this->creator->create($name, $this->getMigrationPath(), $table, $create);

        if ($this->plan !== null && $file !== $this->existingArtifacts()->absolute($this->plan->primary->path())) {
            throw GenerationRefused::because("The native creator wrote [{$file}], not the resolved [{$this->plan->primary->path()}].");
        }

        $this->components->info(sprintf('Migration [%s] created successfully.', windows_os() ? str_replace('/', '\\', $file) : $file));
    }
}
