<?php

namespace Tey\Mod\Plans;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Exceptions\ModException;
use Throwable;

/** @internal One formatter for humans and agents; planning never reads a terminal. */
final class PlanWriter
{
    /** @param callable(Plan): void $build */
    public function preview(Command $command, InputInterface $input, callable $build): int
    {
        $raw = $input->hasArgument('name') ? $input->getArgument('name') : null;
        $parts = is_string($raw) ? explode(':', $raw, 2) : [];
        $plan = new Plan((string) $command->getName(), count($parts) === 2 ? $parts[0] : null, $parts === [] ? null : $parts[count($parts) - 1]);
        $output = $command->getOutput();
        $verbosity = $output->getVerbosity();
        $interactive = $input->isInteractive();
        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $input->setInteractive(false);
        try {
            $build($plan);
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException && ! $exception instanceof RuntimeException) {
                throw $exception;
            }
            $plan->warning($exception->getMessage());
        } finally {
            $input->setInteractive($interactive);
            $output->setVerbosity($verbosity);
        }
        $this->write($command, $plan, (bool) $input->getOption('json'));

        return Command::SUCCESS;
    }

    public function write(Command $command, Plan $plan, bool $json): void
    {
        if ($json) {
            $command->getOutput()->writeln(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return;
        }
        if ($plan->command === 'mod:autoload' && $plan->files === [] && $plan->warnings === []) {
            $command->outputComponents()->info('Every root has its Composer mapping configured.');

            return;
        }
        $count = count($plan->files);
        $inserts = count($plan->inserts);
        $suffix = $inserts === 0 ? '' : " and {$inserts} inserts";
        $name = $plan->group === null ? ($plan->name ?? '') : ($plan->name === null ? $plan->group : $plan->group.':'.$plan->name);
        $command->outputComponents()->info($plan->command.' will write '.$count.' '.($count === 1 ? 'file' : 'files').$suffix.($name === '' ? '' : ' for '.$name).'.');
        foreach ($plan->files as $file) {
            $this->line($command, $file['path'], $file['alias'].($file['exists'] ? ' (exists)' : ''));
            $mappings = $file['identity']['mappings'] ?? [];
            if (is_array($mappings)) {
                foreach ($mappings as $namespace => $path) {
                    if (is_string($namespace) && is_string($path)) {
                        $this->line($command, $namespace.': '.$path, 'would add');
                    }
                }
            }
        }
        foreach ($plan->inserts as $index => $insert) {
            $detail = $plan->insertDetails[$index] ?? ['anchor' => '// mod:'.$insert['at'], 'label' => $insert['at']];
            $this->line($command, '+ at '.$detail['anchor'].' in '.basename($insert['into']), $detail['label']);
        }
        $command->newLine();
        foreach ($plan->warnings as $warning) {
            $command->outputComponents()->warn($warning['message']);
        }
    }

    private function line(Command $command, string $path, string $alias): void
    {
        $command->line('  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($alias) - 4)).' '.$alias);
    }
}
