<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;
use Tey\Mod\Commands\MigrationCommand;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;

/**
 * Recipes are evaluated immediately so include() always takes a snapshot.
 *
 * @internal read by command registration and mod:list
 */
final class ScaffoldRegistry
{
    /** @var array<string, array<string, Scaffold>> name => source => recipe */
    private array $recipes = [];

    /** @var array<string, array<string, string>> */
    private array $definitionProblems = [];

    /** @var array<string, string> */
    private array $problems = [];

    /** @var array<string, string> */
    private array $sources = [];

    /** @var array<string, Scaffold> The recipes accepted during command registration. */
    private array $resolved = [];

    /** @param Closure(Scaffold): mixed $recipe
     * @param  ?string  $source  internal override for package registration tooling
     */
    public function register(string $name, Closure $recipe, ?string $source = null): self
    {
        $source ??= $this->caller();
        try {
            $this->recipes[$name][$source] = $this->build($recipe);
            unset($this->definitionProblems[$name][$source]);
        } catch (ModException $exception) {
            $this->recipes[$name][$source] = new Scaffold;
            $this->definitionProblems[$name][$source] = $exception->getMessage();
        }

        return $this;
    }

    /** @param Closure(Scaffold): mixed $recipe */
    public function build(Closure $recipe): Scaffold
    {
        $scaffold = new Scaffold(fn (string $name): ?Scaffold => $this->get($name));
        $recipe($scaffold);

        return $scaffold;
    }

    public function get(string $name): ?Scaffold
    {
        $recipes = $this->recipes[$name] ?? [];
        if (isset($recipes['app'])) {
            return $recipes['app'];
        }

        return count($recipes) === 1 ? array_values($recipes)[0] : null;
    }

    /** @return array<string, Scaffold> */
    public function all(): array
    {
        $recipes = [];
        foreach (array_keys($this->recipes) as $name) {
            if (($recipe = $this->get($name)) !== null) {
                $recipes[$name] = $recipe;
            }
        }

        return $recipes;
    }

    /** Validate only recipes: a broken scaffold never prevents the layout's commands from booting.
     * @param  array<string, Scaffold>  $overrides
     * @param  list<string>  $commands  existing command names, including aliases
     * @return array<string, Scaffold>
     */
    public function resolve(CompiledLayout $layout, string $layoutName, array $overrides = [], array $commands = []): array
    {
        $this->problems = [];
        $this->sources = [];
        $resolved = [];
        $names = array_unique([...array_keys($this->recipes), ...array_keys($overrides)]);
        foreach ($names as $name) {
            $sources = $this->recipes[$name] ?? [];
            $packages = array_values(array_filter(array_keys($sources), static fn (string $source): bool => $source !== 'app'));
            $override = $overrides[$name] ?? null;
            if ($override === null && ! isset($sources['app']) && count($packages) > 1) {
                $this->problems[$name] = "Scaffold [{$name}] is registered by ".implode(' and by ', $packages).", so mod:{$name} is disabled. Define {$name} in your app to use your own.";

                continue;
            }
            $recipe = $override ?? $this->get($name);
            if ($recipe === null) {
                continue;
            }
            $source = $override !== null ? 'layout' : (isset($sources['app']) ? 'app' : ($packages[0] ?? 'app'));
            $this->sources[$name] = $source === 'app' && $packages !== [] ? 'app (overrides '.implode(', ', $packages).')' : $source;
            $issue = $recipe->errors()[0] ?? ($override === null ? ($this->definitionProblems[$name][$source] ?? null) : null);
            if ($issue !== null) {
                $this->problems[$name] = "Scaffold [{$name}] is disabled. {$issue}";

                continue;
            }
            if ($layout->hasKind($name)) {
                $this->problems[$name] = "Scaffold [{$name}] has the name of the {$name} file type, so it is disabled. Give the scaffold another name.";

                continue;
            }
            if (in_array('mod:'.$name, $commands, true)) {
                $this->problems[$name] = "Scaffold [{$name}] has the name of the mod:{$name} command, so it is disabled. Give the scaffold another name.";

                continue;
            }
            if ($recipe->duplicates() !== []) {
                $alias = $recipe->duplicates()[0];
                $this->problems[$name] = "Scaffold [{$name}] has two members named {$alias}. Give one an as: name (as: 'updateRequest'), so mod:{$name} is disabled.";

                continue;
            }
            foreach ($recipe->members() as $member) {
                if (! $layout->hasKind($member->fileType)) {
                    $type = $member->fileType;
                    $token = $layout->dimensionNames()[0] ?? 'group';
                    $folder = Str::pluralStudly(Str::studly($type));
                    $this->problems[$name] = "Scaffold [{$name}] makes a {$type}, which the {$layoutName} layout doesn't have, so mod:{$name} is disabled. Add it (Mod::layout('{$layoutName}')->generates('{$type}', in: '@{$token}/{$folder}')) or override {$name} for this layout.";
                    break;
                }
                if ((! $layout->kind($member->fileType)->isClass() && ! MigrationCommand::supports($layout->kind($member->fileType))) || $layout->kind($member->fileType)->command === null) {
                    $this->problems[$name] = "Scaffold [{$name}] cannot generate the {$member->fileType} file type, so mod:{$name} is disabled. Use a file type with a class or migration generator.";
                    break;
                }
            }
            if (! isset($this->problems[$name])) {
                $resolved[$name] = $recipe;
            }
        }

        return $this->resolved = $resolved;
    }

    /** @return array<string, Scaffold> Recipes accepted with the actual command registry. */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /** @return array<string, string> names and provenance for mod:list */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string, string> names and actionable configuration warnings */
    public function problems(): array
    {
        return $this->problems;
    }

    private function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;
            if (! is_string($class) || ! is_subclass_of($class, ServiceProvider::class)) {
                continue;
            }
            $file = (new ReflectionClass($class))->getFileName();
            if ($file === false) {
                continue;
            }
            $app = Container::getInstance();
            $appPath = $app->bound('path') ? $app->make('path') : null;
            if (is_string($appPath) && Path::relative($appPath, $file) !== null) {
                return 'app';
            }
            for ($directory = dirname($file); dirname($directory) !== $directory; $directory = dirname($directory)) {
                $manifest = $directory.'/composer.json';
                if (! is_file($manifest)) {
                    continue;
                }
                $json = json_decode((string) file_get_contents($manifest), true);
                $name = is_array($json) ? ($json['name'] ?? null) : null;

                return is_string($name) && $name !== 'tey/mod' ? $name : 'app';
            }
        }

        return 'app';
    }
}
