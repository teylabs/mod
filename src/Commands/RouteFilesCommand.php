<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\BuiltIn\RouteContent;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Routing\RoutePaths;
use Throwable;

use function Laravel\Prompts\confirm;

/** @internal The two route generators preflight the whole write before touching files. */
abstract class RouteFilesCommand extends Command
{
    /** @return list<array{path: string, source: string, type: string, class: ?string}> */
    abstract protected function files(RoutePaths $paths, string $group): array;

    public function handle(CompiledLayout $layout, PlanWriter $writer): int
    {
        $group = $this->argument(RouteContent::ARGUMENT);
        if (! is_string($group)) {
            throw new \InvalidArgumentException('Pass a group name to '.$this->getName().'.');
        }
        $build = function (Plan $plan) use ($layout, $group): void {
            $plan->group = $group;
            $plan->name = null;
            foreach ($this->files(new RoutePaths($layout), $group) as $file) {
                $plan->file($file['type'], $file['type'], $file['path'], ['path' => $file['path']], is_file($this->laravel->basePath($file['path'])), $file['class']);
            }
            $plan->collisions((bool) $this->option('force'));
        };
        if ($this->option('dry-run')) {
            return $writer->preview($this, $this->input, $build);
        }
        try {
            $files = $this->files(new RoutePaths($layout), $group);
            foreach ($files as $file) {
                if (! is_file($this->laravel->basePath($file['path'])) || $this->option('force')) {
                    continue;
                }
                $message = $this->getName().': '.$file['path'].' already exists.';
                if (! $this->input->isInteractive() || (! $this->laravel->runningUnitTests() && (! stream_isatty(STDIN) || filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)))) {
                    $this->components->error($message.' Pass --force to overwrite it. Nothing was written.');

                    return self::FAILURE;
                }
                if (! confirm($message.' Overwrite it?', default: false)) {
                    return self::SUCCESS;
                }
            }
            foreach ($files as $file) {
                $absolute = $this->laravel->basePath($file['path']);
                $this->laravel->make('files')->ensureDirectoryExists(dirname($absolute));
                $this->laravel->make('files')->put($absolute, $file['source']);
                $label = $file['type'] === 'route-registrar' ? 'Route registrar' : 'Route file';
                $this->components->info($label.' ['.$file['path'].'] created successfully.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException) {
                throw $exception;
            }
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
