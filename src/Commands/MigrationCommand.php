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
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\GenerationRefused;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\ModMigrationCreator;

/**
 * Native make:migration, placed by the preset.
 *
 * The timestamp comes from the native creator's clock for the resolved
 * directory, feeds the timestamped name policy, and is pinned so the native
 * creator writes exactly the resolved file.
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

    /**
     * Resolve and check the migration, then let the native command write it with the pinned timestamp.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $previous = $this->migration;

        try {
            if ($this->input->getOption('path') !== null || $this->input->getOption('realpath')) {
                throw GenerationRefused::because('mod:* places migrations from the layout; use --in instead of --path/--realpath.');
            }

            $argument = $this->input->getArgument('name');
            $name = Str::snake(trim(is_string($argument) ? $argument : ''));
            $context = $this->placementContext();

            // The directory never depends on the timestamp; resolve it first to read the native clock there.
            $directory = dirname($this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => '0000_00_00_000000'])->path());
            $timestamp = $this->modCreator->datePrefixFor($this->existingArtifacts()->absolute($directory));

            $this->migration = $this->resolveArtifact($this->kind()->id, $name, $context, ['timestamp' => $timestamp]);
            $this->refuseCollisions(new GenerationPlan($this->migration), false);

            return $this->modCreator->pinned($timestamp, fn (): int => parent::execute($input, $output));
        } catch (ModException $exception) {
            return $this->refused($exception);
        } finally {
            $this->migration = $previous;
        }
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
