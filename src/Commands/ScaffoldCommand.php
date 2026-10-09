<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Commands\Concerns\InteractsWithLayout;
use Tey\Mod\Commands\Concerns\RunsScaffoldTree;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\BuiltIn\GeneratorSources;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Scaffolds\Member;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Placeholders;
use Tey\Mod\Scaffolds\QuestionAnswers;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Scaffolds\ScaffoldPlan;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\TemplateCatalog;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/** One command per recipe, using the normal adapters to plan and generate. */
final class ScaffoldCommand extends Command
{
    use InteractsWithLayout;
    use RunsScaffoldTree;

    /** @var array<string, list<string>> file type => template slot options */
    private array $memberSlots = [];

    protected $signature = 'mod:scaffold {name} {--force} {--skip-existing} {--dry-run} {--json}';

    public function __construct(private readonly string $recipeName, private Scaffold $recipe, private readonly CompiledLayout $preset)
    {
        parent::__construct();
        $this->setName('mod:'.$recipeName);
        $this->setDescription('Generate the '.$recipeName.' scaffold');
        $this->treeOptions();
        foreach ($recipe->questions() as $question) {
            $mode = match ($question->type) {
                'list' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'confirm' => InputOption::VALUE_NEGATABLE,
                default => InputOption::VALUE_REQUIRED,
            };
            if (! $this->getDefinition()->hasOption($question->name)) {
                $this->getDefinition()->addOption(new InputOption($question->name, null, $mode, $question->label ?? $question->name));
            }
        }
        foreach ($preset->placementOptions() as $dimension => $option) {
            $this->getDefinition()->addOption(new InputOption($option, null, InputOption::VALUE_REQUIRED, 'Place in this '.$dimension));
        }
        $this->getDefinition()->addOption(new InputOption('in', null, InputOption::VALUE_REQUIRED, 'Placement in the layout’s group order'));
        foreach ($recipe->members() as $member) {
            $this->memberSlots[$member->fileType] = $this->scaffoldSlots($member->fileType);
            foreach ($this->memberSlots[$member->fileType] as $slot) {
                if (! $this->getDefinition()->hasOption($slot)) {
                    $this->getDefinition()->addOption(new InputOption($slot, null, InputOption::VALUE_REQUIRED, 'The '.$slot.' folder and template value'));
                }
            }
        }
    }

    /** @return list<string> */
    private function scaffoldSlots(string $fileType): array
    {
        $slots = array_values(array_diff($this->preset->rule($fileType)->dimensions(), $this->preset->dimensionNames()));
        $container = Container::getInstance();
        if ($container->resolved(TemplateCatalog::class)) {
            foreach ($container->make(TemplateCatalog::class)->variants()[$fileType] ?? [] as $record) {
                $slots = [...$slots, ...$record['slots']];
            }
        }

        return array_values(array_unique($slots));
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

    private ?Plan $dryPlan = null;

    private ?ScaffoldRegistry $recipeRegistry = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dryPlan = null;
        $registry = $this->laravel->make(ScaffoldRegistry::class);
        $original = $this->recipe;
        if ($registry->hasGroupRecipe($this->recipeName)) {
            $group = implode('/', $this->placementContext()->toArray());
            $this->recipeRegistry = $registry->forGroup($group);
            $accepted = $this->recipeRegistry->resolve($this->preset, $this->layoutName());
            $selected = $accepted[$this->recipeName] ?? null;
            if ($selected === null) {
                $message = $this->recipeRegistry->problems()[$this->recipeName] ?? sprintf(GeneratorSources::WRONG_OWNER, $this->recipeName);
                $this->recipeRegistry = null;
                if ($input->getOption('dry-run')) {
                    return (new PlanWriter)->preview($this, $input, static fn (Plan $plan) => $plan->warning($message));
                }
                $this->components->error($message);

                return self::FAILURE;
            }
            $this->recipe = $selected;
        }
        try {
            return $this->executeRecipe($input, $output);
        } finally {
            $this->recipe = $original;
            $this->recipeRegistry = null;
        }
    }

    private function executeRecipe(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run')) {
            return (new PlanWriter)->preview($this, $input, function (Plan $preview) use ($input, $output): void {
                $this->dryPlan = $preview;
                parent::execute($input, $output);
            });
        }

        return parent::execute($input, $output);
    }

    private function describePlan(ScaffoldPlan $plan): void
    {
        if ($this->dryPlan === null) {
            return;
        }
        $scope = $this->scaffoldExecution();
        foreach ($plan->files() as $file) {
            if ($scope !== null && $file['artifact']->kind->extension !== null) {
                $artifact = $file['artifact'];
                $source = $scope->variants[$artifact->path()] ?? $scope->defaultStubs[$artifact->path()] ?? '';
                if (is_file($source)) {
                    $warnings = [];
                    (new Placeholders(['name' => $artifact->name, ...$scope->values[$artifact->path()]]))->renderPlain((string) file_get_contents($source), $artifact->kind->extension ?? '', Path::relative($this->laravel->basePath(), $source) ?? $source, $warnings);
                    foreach ($warnings as $warning) {
                        $this->dryPlan->warning($warning['message'], blocking: false, file: $warning['file'], line: $warning['line']);
                    }
                }
            }
            $this->dryPlan->artifact($file['alias'], $file['artifact'], $this->laravel->basePath(), $file['member']?->existing);
        }
        $this->dryPlan->collisions((bool) $this->option('force'), (bool) $this->option('skip-existing'));
    }

    public function handle(): int
    {
        if ($this->recipe->parts() !== [] || $this->recipe instanceof Part) {
            return $this->handleTree();
        }
        $scope = new ScaffoldExecution;
        $previous = $this->scaffoldExecution();
        $this->laravel->instance(ScaffoldExecution::class, $scope);
        try {
            $plan = new ScaffoldPlan;
            $publish = [];
            $calls = [];
            $inputName = $this->rawNameInput();
            $name = $this->shorthand()[1];
            $context = $this->placementContext();
            $values = ['name' => $name];
            $answers = new QuestionAnswers($this->preset, $this->laravel->basePath());
            foreach ($this->recipe->questions() as $question) {
                try {
                    $values[$question->name] = $answers->answer($question, $this->option($question->name), $this->interactive(), 'mod:'.$this->recipeName, $name);
                } catch (Throwable $exception) {
                    if ($this->dryPlan === null || ! $exception instanceof ModException) {
                        throw $exception;
                    }
                    $this->dryPlan->warning($exception->getMessage());
                }
            }
            if ($this->dryPlan !== null && $this->dryPlan->warnings !== []) {
                return self::SUCCESS;
            }
            foreach ($this->recipe->members() as $alias => $member) {
                $kind = $this->preset->kind($member->fileType);
                $command = $kind->command ?? throw GenerationRefused::because("File type [{$member->fileType}] has no command. Enable its command to use mod:{$this->recipeName}.");
                $arguments = ['name' => $member->name === null ? $name : (new Placeholders($values))->name($member->name)];
                $scope->ungrouped = $member->ungrouped;
                $placement = $this->inOption($this->memberContext($member, $context, $values));
                if ($placement !== '') {
                    $arguments['--in'] = $placement;
                }
                if (! $this->interactive()) {
                    $arguments['--no-interaction'] = true;
                }
                foreach ($this->memberSlots[$member->fileType] ?? [] as $slot) {
                    $value = $this->option($slot);
                    if (is_string($value) && $value !== '') {
                        $arguments['--'.$slot] = $value;
                    }
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
                $plan->add($alias, $memberPlan, $scope, $member);
                if ($placement !== '') {
                    $arguments['--in'] = $this->inOption($memberPlan->primary->context);
                }
                $calls[] = [$command, $arguments, $member->ungrouped];
                // The first member may settle a typo or case difference. Every member uses that placement.
                if (! $member->ungrouped && $member->group === null) {
                    $context = $memberPlan->primary->context;
                }
            }

            foreach ($plan->files() as ['artifact' => $artifact, 'member' => $member]) {
                $scope->values[$artifact->path()] = [...$scope->aliases, ...$values, ...($member?->ungrouped ? ['name' => $artifact->name] : [])];
            }
            foreach ($this->recipe->members() as $alias => $member) {
                if ($member->stub === null) {
                    continue;
                }
                $primary = $scope->aliases[$alias];
                $extension = $primary->kind->extension ?? '';
                $relative = 'stubs/mod.'.$member->fileType.'.'.$member->stub.$extension.'.stub';
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
                    $publish[$file] = [$source, $relative, $member->fileType];
                    $variant = $file;
                }
                $scope->variants[$primary->path()] = $variant;
            }

            foreach ($scope->defaultStubs as $path => $default) {
                $selected = $scope->variants[$path] ?? $default;
                if ($selected !== '' && ! isset($publish[$selected]) && ! is_file($selected)) {
                    throw GenerationRefused::because("Template [{$selected}] does not exist. Create it before running mod:{$this->recipeName}. Nothing was written.");
                }
            }
            $this->describePlan($plan);
            $existing = $plan->existing($this->existingArtifacts());
            if ($this->dryPlan !== null) {
                return self::SUCCESS;
            }
            $scope->keep = $scope->silentKeep = $plan->kept($this->laravel->basePath());
            $count = count($plan->files()) - count($scope->keep);
            $noun = $count === 1 && $scope->keep !== [] ? 'file' : 'files';
            $this->components->info("mod:{$this->recipeName} will write {$count} {$noun} for {$inputName}.");
            foreach ($plan->files() as $file) {
                $artifact = $file['artifact'];
                $path = $artifact->path();
                $label = ScaffoldPlan::label($file, is_file($this->laravel->basePath($path)));
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
                $scope->keep = [...$scope->keep, ...($choice === $keep ? $existing : [])];
            } elseif ($existing !== [] && ! $scope->force) {
                $scope->keep = [...$scope->keep, ...$existing];
            } elseif ($this->interactive() && ! confirm("Write these {$count} files?", default: true)) {
                return self::SUCCESS;
            }

            foreach ($publish as $file => [$source, $relative, $fileType]) {
                $this->laravel->make('files')->ensureDirectoryExists(dirname($file));
                $this->laravel->make('files')->copy($source, $file);
                $this->components->info("Published stub [{$relative}] from the {$fileType} stub. Edit it to make it the house {$fileType}.");
            }
            $scope->planning = false;
            foreach ($calls as [$command, $arguments, $ungrouped]) {
                $scope->ungrouped = $ungrouped;
                if ($scope->force && $this->getApplication()?->find($command)->getDefinition()->hasOption('force')) {
                    $arguments['--force'] = true;
                }
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    throw GenerationRefused::because("Generating mod:{$this->recipeName} failed.");
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException) {
                throw $exception;
            }
            if ($this->dryPlan !== null) {
                throw $exception;
            }
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $this->components->error($line);
            }

            return self::FAILURE;
        } finally {
            $previous === null ? $this->laravel->forgetInstance(ScaffoldExecution::class) : $this->laravel->instance(ScaffoldExecution::class, $previous);
        }
    }

    /** @param array<string, mixed> $values */
    private function memberContext(Member $member, PlacementContext $context, array $values): PlacementContext
    {
        $scope = $this->scaffoldExecution();
        if ($scope !== null) {
            $scope->groupFlag = $member->group === null ? null : (preg_match('/\{\{\s*(\w+)\s*\}\}/', $member->group, $match) === 1 ? '--'.$match[1] : '--in');
        }
        if ($member->ungrouped) {
            return PlacementContext::none();
        }
        if ($member->group !== null) {
            $group = (new Placeholders($values))->render($member->group);
            if ($group === '' || str_contains($group, '{{')) {
                throw GenerationRefused::because("mod:{$this->recipeName} needs a member group. Answer its group question with the corresponding flag.");
            }

            return PlacementContext::fromOption($group, $this->preset);
        }

        return $context;
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
    }
}
