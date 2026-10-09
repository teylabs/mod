<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Install\InertiaEdits;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Support\Stack;

use function Laravel\Prompts\confirm;

class InstallCommand extends Command
{
    protected $signature = 'mod:install {stack : The frontend stack to wire (inertia)}
        {--dry-run : Preview every edit without writing}
        {--json : Print the dry-run plan as JSON}';

    protected $description = 'Wire Inertia pages, Vite and Tailwind';

    protected function terminalAvailable(): bool
    {
        return $this->laravel->runningUnitTests()
            || (defined('STDIN') && stream_isatty(STDIN) && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOL));
    }

    public function handle(CompiledLayout $layout, Stack $stack): int
    {
        if ($this->argument('stack') !== 'inertia') {
            $this->components->error('mod:install supports inertia. Run mod:install inertia.');

            return self::FAILURE;
        }
        $plan = (new InertiaEdits($this->laravel->basePath(), $layout, $stack))->plan();
        if ($this->option('json')) {
            if (! $this->option('dry-run')) {
                $this->components->error('mod:install --json needs --dry-run. Run mod:install inertia --dry-run --json.');

                return self::FAILURE;
            }
            (new PlanWriter)->write($this, $plan, true);

            return self::SUCCESS;
        }
        if ($stack->inertia() === null) {
            $this->components->info('Blade apps need no Inertia install.');

            return self::SUCCESS;
        }
        if ($plan->warnings === [] && $plan->files === []) {
            $this->components->info('Already wired.');

            return self::SUCCESS;
        }
        $count = count($plan->files);
        $this->components->info('mod:install inertia will change '.$count.' files (Inertia with '.ucfirst($stack->inertia()).($stack->typescript() ? ' and TypeScript' : '').' detected).');
        foreach ($plan->files as $file) {
            $this->line('  '.$file['path'].' '.str_repeat('.', max(2, 27 - strlen($file['path']))).' '.$file['alias']);
        }
        $this->newLine();
        foreach ($plan->warnings as $warning) {
            $this->components->warn($warning['message']);
        }
        if ($plan->warnings !== []) {
            foreach ($plan->files as $file) {
                $this->line('Manual edits for ['.$file['path'].']:');
                if (is_string($file['identity']['after'] ?? null) && is_string($file['identity']['before'] ?? null)) {
                    $added = array_diff(explode("\n", $file['identity']['after']), explode("\n", $file['identity']['before']));
                    $this->getOutput()->writeln(array_values($added), OutputInterface::OUTPUT_RAW);
                }
            }
        }
        if (! $plan->wouldWrite || $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($this->input->isInteractive()) {
            if (! $this->terminalAvailable()) {
                $this->components->error('mod:install inertia needs confirmation. Pass --no-interaction to apply these changes.');

                return self::FAILURE;
            }
            if (! confirm('Apply these changes?', default: true)) {
                return self::SUCCESS;
            }
        }
        foreach ($plan->files as $file) {
            if (is_string($file['identity']['after'] ?? null)) {
                file_put_contents($this->laravel->basePath($file['path']), $file['identity']['after']);
            }
        }

        return self::SUCCESS;
    }
}
