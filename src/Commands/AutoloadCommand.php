<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\ComposerJson;

use function Laravel\Prompts\text;

class AutoloadCommand extends Command
{
    protected $signature = 'mod:autoload
        {--dry-run : Show the missing entries without writing or running Composer}
        {--no-dump : Add the missing entries without running Composer}
        {--namespace= : Namespace for a group path outside the layout roots}';

    protected $description = 'Add missing Composer PSR-4 entries for the active layout';

    /**
     * The layout read is kept here for lane 3's namespaceFor() integration.
     *
     * @return list<array{namespace: string, path: string, inferred?: bool}>
     */
    protected function autoloadRoots(CompiledLayout $layout): array
    {
        $roots = [];

        foreach ($layout->roots() as $root) {
            if ($root->namespace !== null) {
                $roots[] = ['namespace' => $root->namespace, 'path' => $root->path];
            }
        }

        return $roots;
    }

    public function handle(CompiledLayout $layout): int
    {
        try {
            return $this->sync($layout);
        } catch (RuntimeException $exception) {
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
                $this->components->warn("mod:autoload needs [{$namespace}] at [{$path}], but composer.json maps it to [{$folders}]. Resolve the mapping in composer.json and run mod:autoload again.");
                $conflict = true;

                continue;
            }

            $missing[$namespace] = $path;
        }

        if ($conflict) {
            return self::FAILURE;
        }

        $configured = $this->laravel->make('config')->get('mod.layout', 'laravel');
        $name = is_string($configured) ? $configured : 'active';

        if ($missing === []) {
            $this->components->info("Every root of the {$name} layout is autoloaded.");

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
            $composer = $this->laravel->make(Composer::class)->setWorkingPath($this->laravel->basePath());

            try {
                $status = $composer->dumpAutoloads();
            } catch (ProcessStartFailedException) {
                $status = 127;
            }

            if (in_array($status, [126, 127, 9009], true)) {
                $this->components->warn('Run composer dump-autoload to load the new entries.');
            } elseif ($status !== 0) {
                $this->components->warn('mod:autoload added the entries, but composer dump-autoload failed. Fix the Composer error and run composer dump-autoload again.');

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
