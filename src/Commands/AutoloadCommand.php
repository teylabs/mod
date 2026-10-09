<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Plans\PlanWriter;
use Tey\Mod\Support\ComposerJson;
use Throwable;

use function Laravel\Prompts\text;

class AutoloadCommand extends Command
{
    protected $signature = 'mod:autoload
        {--dry-run : Show the missing entries without writing or running Composer}
        {--json : Print the dry-run plan as JSON}
        {--no-dump : Add the missing entries without running Composer}
        {--namespace= : Namespace for a group path outside the layout roots}';

    protected $description = 'Add missing Composer PSR-4 entries for the active layout';

    /**
     * @return list<array{namespace: string, path: string, inferred?: bool}>
     */
    protected function autoloadRoots(CompiledLayout $layout): array
    {
        $roots = [];

        foreach ($layout->roots() as $name => $root) {
            if ($root->namespace !== null) {
                // GroupPath creates these roots for group folders outside every mount.
                $inferred = $name === 'group_'.substr(hash('sha256', $root->path), 0, 12);
                $roots[] = [
                    'namespace' => $inferred ? $layout->namespaceFor($root->path) : $root->namespace,
                    'path' => $root->path,
                    'inferred' => $inferred,
                ];
            }
        }

        return $roots;
    }

    private ?Plan $dryPlan = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dryPlan = null;
        if ($input->getOption('dry-run')) {
            return (new PlanWriter)->preview($this, $input, function (Plan $preview) use ($input, $output): void {
                $this->dryPlan = $preview;
                parent::execute($input, $output);
            });
        }

        return parent::execute($input, $output);
    }

    public function handle(CompiledLayout $layout): int
    {
        try {
            return $this->sync($layout);
        } catch (RuntimeException $exception) {
            if ($this->dryPlan !== null) {
                throw $exception;
            }
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function sync(CompiledLayout $layout): int
    {
        $json = new ComposerJson($this->laravel->basePath('composer.json'));
        $missing = [];
        $conflict = false;

        foreach ($this->autoloadRoots($layout) as $root) {
            if ($json->covers($root['namespace'], $root['path'])) {
                continue;
            }

            $path = $json->normalizePathForComposer($root['path']);
            $namespace = ($root['inferred'] ?? false)
                ? $this->chooseNamespace(rtrim($path, '/'), $root['namespace'])
                : $root['namespace'];

            if ($json->covers($namespace, $path)) {
                continue;
            }
            $mapped = $json->mappings()[$namespace] ?? null;

            if ($mapped !== null || (isset($missing[$namespace]) && $missing[$namespace] !== $path)) {
                $folders = implode(', ', (array) ($mapped ?? $missing[$namespace]));
                $message = "mod:autoload needs [{$namespace}] at [{$path}], but composer.json maps it to [{$folders}]. Resolve the mapping in composer.json and run mod:autoload again.";
                $this->dryPlan?->warning($message, 'composer.json');
                $this->components->warn($message);
                $conflict = true;

                continue;
            }

            $missing[$namespace] = $path;
        }

        if ($this->dryPlan !== null) {
            if ($missing !== []) {
                $this->dryPlan->file('autoload', 'composer', 'composer.json', ['mappings' => $missing], true);
            } else {
                $this->dryPlan->wouldWrite = false;
            }

            return self::SUCCESS;
        }
        if ($conflict) {
            return self::FAILURE;
        }

        $configured = $this->laravel->make('config')->get('mod.layout', 'laravel');
        $name = is_string($configured) ? $configured : 'active';

        if ($missing === []) {
            $this->components->info("Every root of the {$name} layout has its Composer mapping configured.");

            $this->components->info('If classes do not load, run composer dump-autoload.');

            return self::SUCCESS;
        }

        $count = count($missing);
        $entries = $count === 1 ? 'entry' : 'entries';
        $this->components->info("composer.json is missing {$count} autoload {$entries} for the {$name} layout.");

        foreach ($missing as $namespace => $path) {
            $json->register($namespace, $path);
            $label = json_encode($namespace, JSON_THROW_ON_ERROR).': '.json_encode($path, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->components->twoColumnDetail($label, $this->option('dry-run') ? 'would add' : 'added');
        }

        if ($this->option('dry-run')) {
            $this->newLine();

            return self::SUCCESS;
        }

        $json->save();

        if (! $this->option('no-dump')) {
            try {
                $composer = $this->laravel->make(Composer::class)->setWorkingPath($this->laravel->basePath());
                $status = $composer->dumpAutoloads();
            } catch (Throwable $exception) {
                $this->components->error($exception->getMessage());
                $this->components->warn('composer.json was updated; run composer dump-autoload.');

                return self::FAILURE;
            }

            if ($status !== 0) {
                $this->components->warn('mod:autoload: composer dump-autoload failed. composer.json was updated; run composer dump-autoload.');

                return self::FAILURE;
            } else {
                $this->components->twoColumnDetail('composer dump-autoload', 'DONE');
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function chooseNamespace(string $path, string $default): string
    {
        $namespace = $this->option('namespace');
        $validate = static fn (string $value): ?string => preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\\\\?$/D', $value) === 1
            ? null : 'Enter a PHP namespace such as Areas\\.';

        if (! is_string($namespace)) {
            $interactive = $this->input->isInteractive()
                && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));

            if ($interactive) {
                $namespace = text("{$path} isn't autoloaded. Which namespace should it use?", default: $default, required: true, validate: $validate);
            } else {
                $namespace = $default;
                $this->components->info("Using namespace [{$default}] for [{$path}]; pass --namespace to choose another.");
            }
        }

        if ($validate($namespace) !== null) {
            throw new RuntimeException('mod:autoload needs a valid PHP namespace. Pass --namespace=Areas\\ and run mod:autoload again.');
        }

        return rtrim($namespace, '\\').'\\';
    }
}
