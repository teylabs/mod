<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Install\AssetEdits;
use Tey\Mod\Install\FrontendWiring;
use Tey\Mod\Install\InertiaEdits;
use Tey\Mod\Install\Wiring;
use Tey\Mod\Install\WiringEdits;
use Tey\Mod\Install\WiringPlan;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Pages\PageRoots;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Support\Stack;

use function Laravel\Prompts\confirm;

class InstallCommand extends Command
{
    protected $signature = 'mod:install {stack : The frontend stack to wire (inertia or assets)}
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
        if (! in_array($this->argument('stack'), ['inertia', 'assets'], true)) {
            $this->components->error('mod:install supports inertia and assets. Run mod:install inertia or mod:install assets.');

            return self::FAILURE;
        }
        $this->laravel->make(PageDirectories::class)->namespaces();
        $packages = new PageRoots;
        foreach ($this->laravel->make(PageDirectories::class)->additional() as $namespace => $directory) {
            $packages->register($namespace, $directory, $this->laravel->make(PageDirectories::class)->entries($namespace));
        }
        $mode = $this->argument('stack');
        $plan = $mode === 'assets' ? new Plan('mod:install', name: 'assets') : (new InertiaEdits($this->laravel->basePath(), $layout, $stack, packages: $packages))->plan();
        $wiring = FrontendWiring::forLayout($this->laravel->make(Wiring::class), $layout);
        $plan = (new WiringEdits($this->laravel->basePath(), $layout, $stack, $wiring))->plan($plan);
        foreach ($this->laravel->make(PageDirectories::class)->notices() as $notice) {
            $plan->warning($notice['message'], blocking: false, code: $notice['code'], data: ['path' => $notice['path']]);
        }
        $plan = (new AssetEdits($this->laravel->basePath(), $layout))->plan($plan);
        if ($this->option('json')) {
            if (! $this->option('dry-run')) {
                $this->components->error('mod:install --json needs --dry-run. Run mod:install inertia --dry-run --json.');

                return self::FAILURE;
            }
            (new PlanWriter)->write($this, $plan, true);

            return self::SUCCESS;
        }
        if ($mode === 'inertia' && $stack->inertia() === null && $plan->files === [] && $plan->warnings === [] && $wiring->aliases() === [] && $wiring->sources() === [] && $wiring->styles() === [] && $packages->all() === []) {
            $this->components->info('Blade apps need no Inertia install.');

            return self::SUCCESS;
        }
        if ($plan->warnings === [] && $plan->files === []) {
            $this->components->info('Already wired.');

            return self::SUCCESS;
        }
        $count = count($plan->files);
        $this->components->info('mod:install '.$mode.' will change '.$count.' files (Inertia with '.ucfirst($stack->inertia() ?? 'frontend').($stack->typescript() ? ' and TypeScript' : '').' detected).');
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
        if (! (new WiringPlan($this->laravel->basePath(), $plan))->apply()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
