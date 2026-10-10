<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Preparation;
use Tey\Mod\Rename\RecipeOptions;
use Tey\Mod\Rename\RecoveryInspector;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/** @internal Rename planning is independent of generation and all file mutation. */
final class RenameCommand extends Command
{
    protected $signature = 'mod:rename {old? : Existing qualified cluster identity} {new? : New qualified cluster identity}
        {--scaffold= : Effective source recipe}
        {--answer=* : Qualified nested recipe answer as key=JSON}
        {--yes : Confirm the reviewed plan non-interactively}
        {--dry-run : Preview without writes or prompts}
        {--json : Emit only preview JSON}
        {--table-migration : Request an exact reversible table migration candidate}
        {--recover : Recover an interrupted rename without normal cluster preflight}';

    protected $description = 'Preview and rename an explicitly selected recipe-owned cluster';

    private RecipeOptions $recipeOptions;

    public function __construct(ScaffoldRegistry $registry, CompiledLayout $layout)
    {
        parent::__construct();
        $this->recipeOptions = new RecipeOptions;
        $this->recipeOptions->add($this->getDefinition(), $registry, $layout);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('json') && ! $input->getOption('dry-run')) {
            $this->components->error('mod:rename --json is a preview option. Add --dry-run. Nothing was written.');

            return self::FAILURE;
        }

        return parent::execute($input, $output);
    }

    public function handle(Planner $planner): int
    {
        $preview = (bool) $this->option('dry-run');
        $interactive = $this->input->isInteractive() && ($this->laravel->runningUnitTests() || (stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)));
        $scaffold = $this->option('scaffold');
        $recover = (bool) $this->option('recover');
        if (! $preview && ! $recover && (! is_string($scaffold) || $scaffold === '') && $interactive) {
            $choices = array_keys($this->laravel->make(ScaffoldRegistry::class)->resolved());
            if ($choices !== []) {
                $scaffold = select('Which scaffold describes this cluster?', $choices);
            }
        }
        $answers = [];
        foreach ($this->recipeOptions->names() as $name) {
            $value = $this->option($name);
            if ($value !== null && $value !== []) {
                $answers[$name] = $value;
            }
        }
        $answerError = null;
        foreach ((array) $this->option('answer') as $answer) {
            if (! is_string($answer) || ! str_contains($answer, '=')) {
                $answerError = 'mod:rename --answer needs a qualified recipe key=JSON value. Nothing was written.';
                break;
            }
            [$key, $json] = explode('=', $answer, 2);
            $value = json_decode($json, true);
            if ($key === '' || json_last_error() !== JSON_ERROR_NONE || array_key_exists($key, $answers)) {
                $answerError = 'mod:rename --answer needs unique qualified keys and valid JSON values. Nothing was written.';
                break;
            }
            $answers[$key] = $value;
        }
        ksort($answers);
        $old = $this->argument('old');
        $new = $this->argument('new');
        $request = new Request(is_string($old) ? $old : null, is_string($new) ? $new : null, is_string($scaffold) ? $scaffold : null, $answers, (bool) $this->option('table-migration'), $recover, (bool) $this->option('yes'), $interactive);
        $build = $planner->build(...);
        $show = fn (Result $result) => (new PlanWriter)->write($this, $result->plan, $preview && (bool) $this->option('json'));
        if ($preview) {
            $result = $recover && $this->laravel->bound(RecoveryInspector::class) ? $this->laravel->make(RecoveryInspector::class)->inspect($request) : $build($request);
            if ($answerError !== null) {
                $result->plan->warning($answerError);
            }
            $show($result);

            return self::SUCCESS;
        }
        if ($answerError !== null) {
            $this->components->error($answerError);

            return self::FAILURE;
        }
        $confirmation = function () use ($interactive, $recover): bool {
            if ((bool) $this->option('yes')) {
                return true;
            }
            if (! $interactive) {
                $this->components->error('mod:rename requires confirmation. Review --dry-run, then pass --yes --no-interaction. Nothing was written.');

                return false;
            }
            if (! confirm($recover ? 'Restore this interrupted rename?' : 'Rename this cluster and stage the changes?', default: false)) {
                $this->line('Rename cancelled. Nothing was written.');

                return false;
            }

            return true;
        };
        if ($recover) {
            if ($this->laravel->bound(Executor::class)) {
                return $this->laravel->make(Executor::class)->recover($request, $show, $confirmation);
            }
        } else {
            if ($this->laravel->bound(Executor::class)) {
                $prepared = false;
                $prepareAndBuild = function (Request $request) use ($interactive, $build, &$prepared): Result {
                    if (! $prepared && $interactive && $this->laravel->bound(Preparation::class)) {
                        $request = $this->laravel->make(Preparation::class)->prepare($request, $build, $this);
                    }

                    $prepared = true;

                    return $build($request);
                };

                return $this->laravel->make(Executor::class)->execute($request, $prepareAndBuild, $show, $confirmation);
            }
            $result = $build($request);
            $show($result);
            if (! $result->plan->wouldWrite) {
                return self::FAILURE;
            }
        }
        $this->components->error('mod:rename executor is unavailable. Install the complete rename implementation before executing. Nothing was written.');

        return self::FAILURE;
    }
}
