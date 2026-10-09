<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Tey\Mod\Commands\Concerns\InteractsWithLayout;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Scaffolds\ScaffoldPlan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/** One command per recipe, using the normal adapters to plan and generate. */
final class ScaffoldCommand extends Command
{
    use InteractsWithLayout;

    protected $signature = 'mod:scaffold {name} {--force} {--skip-existing}';

    public function __construct(private readonly string $recipeName, private readonly Scaffold $recipe, private readonly CompiledLayout $preset)
    {
        parent::__construct();
        $this->setName('mod:'.$recipeName);
        $this->setDescription('Generate the '.$recipeName.' scaffold');
        foreach ($preset->placementOptions() as $dimension => $option) {
            $this->getDefinition()->addOption(new InputOption($option, null, InputOption::VALUE_REQUIRED, 'Place in this '.$dimension));
        }
        $this->getDefinition()->addOption(new InputOption('in', null, InputOption::VALUE_REQUIRED, 'Placement in the layout’s group order'));
    }

    protected function resolveLayout(): CompiledLayout
    {
        return $this->preset;
    }

    /** @return array<string, ?string> */
    protected function placementOptions(): array
    {
        $options = ['in' => null];
        foreach ($this->preset->placementOptions() as $dimension => $option) {
            $options[$option] = $dimension;
        }

        return $options;
    }

    public function handle(): int
    {
        $scope = new ScaffoldExecution;
        $previous = $this->scaffoldExecution();
        $this->laravel->instance(ScaffoldExecution::class, $scope);
        try {
            $plan = new ScaffoldPlan;
            $calls = [];
            $inputName = $this->rawNameInput();
            $name = $this->shorthand()[1];
            $context = $this->placementContext();
            foreach ($this->recipe->members() as $alias => $member) {
                $kind = $this->preset->kind($member->fileType);
                $command = $kind->command ?? throw GenerationRefused::because("File type [{$member->fileType}] has no command. Enable its command to use mod:{$this->recipeName}.");
                $arguments = ['name' => $member->name === null ? $name : str_replace('{name}', $name, $member->name)];
                $placement = $this->inOption($context);
                if ($placement !== '') {
                    $arguments['--in'] = $placement;
                }
                foreach ($member->options as $option => $value) {
                    if (is_int($option) && is_string($value)) {
                        $parts = explode('=', $value, 2);
                        $arguments[$parts[0]] = $parts[1] ?? true;
                    } elseif (is_string($option)) {
                        $arguments[$option] = $value;
                    }
                }
                $scope->reset();
                if ($this->call($command, $arguments) !== self::SUCCESS || $scope->collected() === null) {
                    throw GenerationRefused::because("Planning mod:{$this->recipeName} failed. Nothing was written.");
                }
                $this->configurePrompts($this->input);
                $memberPlan = $scope->collected();
                $scope->aliases[$alias] = $memberPlan->primary;
                $plan->add($alias, $memberPlan, $scope);
                $calls[] = [$command, $arguments];
                // The first member may settle a typo or case difference. Every member uses that placement.
                $context = $memberPlan->primary->context;
            }

            foreach ($this->recipe->members() as $alias => $member) {
                if ($member->stub === null) {
                    continue;
                }
                $primary = $scope->aliases[$alias];
                $relative = 'stubs/mod.'.$member->fileType.'.'.$member->stub.'.stub';
                $registry = $this->laravel->make(StubRegistry::class);
                $file = $this->laravel->basePath($relative);
                $variant = is_file($file) ? $file : $registry->get($member->fileType.'.'.$member->stub)?->path;
                if ($variant === null || ! is_file($variant)) {
                    $message = "The {$this->recipeName} scaffold uses {$relative}, which doesn't exist.";
                    if (! $this->interactive()) {
                        throw GenerationRefused::because($message." Nothing was written.\nCreate {$relative} (start from the {$member->fileType} stub), or remove stub: '{$member->stub}' from the {$member->fileType} member.");
                    }
                    if (! confirm($message." Create it from the {$member->fileType} stub?", default: true)) {
                        return self::SUCCESS;
                    }
                    $source = $scope->defaultStubs[$primary->path()];
                    if (! is_file($source)) {
                        throw GenerationRefused::because("The {$member->fileType} stub [{$source}] does not exist. Nothing was written.");
                    }
                    $this->laravel->make('files')->ensureDirectoryExists(dirname($file));
                    $this->laravel->make('files')->copy($source, $file);
                    $this->components->info("Published stub [{$relative}] from the {$member->fileType} stub. Edit it to make it the house {$member->fileType}.");
                    $variant = $file;
                }
                $scope->variants[$primary->path()] = $variant;
            }

            $existing = $plan->existing($this->existingArtifacts());
            $count = count($plan->files());
            $this->components->info("mod:{$this->recipeName} will write {$count} files for {$inputName}.");
            foreach ($plan->files() as ['artifact' => $artifact, 'alias' => $alias]) {
                $path = $artifact->path();
                $label = $alias.(in_array($path, $existing, true) ? ' (exists)' : '');
                $this->line('  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($label) - 4)).' '.$label);
            }
            $this->newLine();
            $scope->force = (bool) $this->option('force');
            if ($existing !== [] && ! $scope->force && ! $this->option('skip-existing')) {
                if (! $this->interactive()) {
                    throw GenerationRefused::because(implode("\n", array_map(static fn (string $path): string => $path.' already exists.', $existing))."\nNothing was written. Pass --skip-existing to keep it and write the rest, or --force to overwrite it.");
                }
                $number = count($existing);
                $keep = $number === 1 ? 'Keep it, and write the other '.($count - $number) : 'Keep them, and write the other '.($count - $number);
                $overwrite = $number === 1 ? 'Overwrite it' : 'Overwrite them';
                $choice = select($number.' '.($number === 1 ? 'file already exists.' : 'files already exist.').' What should happen?', [$keep, $overwrite, 'Cancel'], default: $keep);
                if ($choice === 'Cancel') {
                    return self::SUCCESS;
                }
                $scope->force = $choice === $overwrite;
                $scope->keep = $choice === $keep ? $existing : [];
            } elseif ($existing !== [] && ! $scope->force) {
                $scope->keep = $existing;
            } elseif ($this->interactive() && ! confirm("Write these {$count} files?", default: true)) {
                return self::SUCCESS;
            }

            $scope->planning = false;
            foreach ($calls as [$command, $arguments]) {
                if ($scope->force && $this->getApplication()?->find($command)->getDefinition()->hasOption('force')) {
                    $arguments['--force'] = true;
                }
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    throw GenerationRefused::because("Generating mod:{$this->recipeName} failed.");
                }
            }

            return self::SUCCESS;
        } catch (ModException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        } finally {
            $previous === null ? $this->laravel->forgetInstance(ScaffoldExecution::class) : $this->laravel->instance(ScaffoldExecution::class, $previous);
        }
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
    }
}
