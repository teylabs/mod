<?php

namespace Tey\Mod\Commands;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Artifact\ResolvedArtifact;
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
 * Hooks: nativePathAllowed() lets a host honour --path/--realpath natively
 * instead of refusing them; beforeGeneration()/afterGeneration() run around
 * the native write.
 */
class MigrationCommand extends MigrateMakeCommand implements GeneratorAdapter
{
    use InteractsWithPreset;

    private ?ResolvedArtifact $migration = null;

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

        $this->addPlacementOption();
    }

    /**
     * Resolve and check the migration, then let the native command write it with the pinned timestamp.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->migration;
        $this->migration = null;
        $exitCode = self::FAILURE;

        try {
            $nativePath = $this->input->getOption('path') !== null || (bool) $this->input->getOption('realpath');

            if ($nativePath && ! $this->nativePathAllowed()) {
                throw GenerationRefused::because('mod:* places migrations from the layout; use --in instead of --path/--realpath.');
            }

            if ($nativePath) {
                return $exitCode = parent::execute($input, $output);
            }

            $name = Str::snake($this->getNameInput());
            $context = $this->placementContext();
            // The native handle() reads the raw argument; hand it the name without the shorthand prefix.
            $this->input->setArgument('name', $this->getNameInput());

            // The directory never depends on the timestamp; resolve it first to read the native clock there.
            $directory = dirname($this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => '0000_00_00_000000'])->path());
            $timestamp = $this->modCreator->datePrefixFor($this->existingArtifacts()->absolute($directory));

            $this->migration = $this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => $timestamp]);
            $plan = new GenerationPlan($this->migration);
            $this->refuseCollisions($plan, false);
            $this->beforeGeneration($plan);

            return $exitCode = $this->modCreator->pinned($timestamp, fn (): int => parent::execute($input, $output));
        } catch (ModException $exception) {
            return $exitCode = $this->reportRefusal($exception);
        } finally {
            if ($this->migration !== null) {
                $this->afterGeneration(new GenerationPlan($this->migration), $exitCode);
            }

            $this->migration = $previous;
        }
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
        if ($this->migration === null) {
            return parent::getMigrationPath();
        }

        return dirname($this->existingArtifacts()->absolute($this->migration->path()));
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

        if ($this->migration !== null && $file !== $this->existingArtifacts()->absolute($this->migration->path())) {
            throw GenerationRefused::because("The native creator wrote [{$file}], not the resolved [{$this->migration->path()}].");
        }

        $this->components->info(sprintf('Migration [%s] created successfully.', windows_os() ? str_replace('/', '\\', $file) : $file));
    }
}
