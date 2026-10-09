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
 * Public node metadata is available through nodes(); recipes stay internal.
 *
 * @phpstan-type Node array{key: string, source: string, from: string, members: array<string, array{fileType: string, name: ?string, stub: ?string, options: array<array-key, mixed>}>, children: list<string>, uses: ?string}
 */
final class ScaffoldRegistry
{
    /** @var array<string, array<string, Scaffold>> name => source => recipe */
    private array $recipes = [];

    /** @var array<string, Scaffold> */
    private array $resolvedNodes = [];

    /** @var array<string, array<string, string>> */
    private array $definitionProblems = [];

    /** @var array<string, string> */
    private array $problems = [];

    /** @var array<string, string> */
    private array $sources = [];

    /** @param (Closure(Scaffold): mixed)|(Closure(Part): mixed) $recipe
     * @param  ?string  $source  internal override for package registration tooling
     */
    public function register(string $name, Closure $recipe, ?string $source = null): self
    {
        $this->resolvedNodes = [];
        $source ??= $this->caller();
        try {
            $this->recipes[$name][$source] = $this->build($recipe, str_contains($name, '.'));
            unset($this->definitionProblems[$name][$source]);
        } catch (ModException $exception) {
            $this->recipes[$name][$source] = new Scaffold;
            $this->definitionProblems[$name][$source] = $exception->getMessage();
        }

        return $this;
    }

    /** @internal Evaluate a node callback with the node type its dot path declares. */
    public function build(Closure $recipe, bool $part = false): Scaffold
    {
        $resolve = fn (string $name): ?Scaffold => $this->get($name);
        $scaffold = $part ? new Part($resolve) : new Scaffold($resolve);
        $recipe($scaffold);

        return $scaffold;
    }

    public function get(string $name): ?Scaffold
    {
        if (isset($this->resolvedNodes[$name])) {
            return $this->resolvedNodes[$name];
        }
        if (str_contains($name, '.') && ! isset($this->recipes[$name])) {
            return $this->all()[$name] ?? null;
        }

        return $this->registered($name);
    }

    private function registered(string $name): ?Scaffold
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
        $nodes = [];
        foreach (array_keys($this->recipes) as $name) {
            if (! str_contains($name, '.') && ($recipe = $this->registered($name)) !== null) {
                $this->flatten($name, $recipe, $nodes, [$name]);
            }
        }
        foreach (array_keys($this->recipes) as $name) {
            if (str_contains($name, '.') && ($recipe = $this->registered($name)) !== null) {
                $nodes[$name] = $recipe;
            }
        }

        return $nodes;
    }

    /** A finite read-only table for tree views and cache metadata.
     * @return array<string, Node>
     */
    public function nodes(): array
    {
        $nodes = [];
        $recipes = $this->resolvedNodes === [] ? $this->all() : $this->resolvedNodes;
        foreach ($recipes as $key => $recipe) {
            if (isset($this->problems[$key])) {
                continue;
            }
            $source = $this->nodeSource($key);
            $members = [];
            $effective = $recipe;
            if ($recipe instanceof Part && $recipe->scaffold() !== null) {
                $effective = clone ($this->registered($recipe->scaffold()) ?? new Scaffold);
                $effective->overlay($recipe);
            }
            foreach ($effective->members() as $alias => $member) {
                $members[$alias] = ['fileType' => $member->fileType, 'name' => $member->name, 'stub' => $member->stub, 'options' => $member->options];
            }
            $children = array_values(array_filter(array_keys($recipes), static fn (string $child): bool => str_starts_with($child, $key.'.') && substr_count($child, '.') === substr_count($key, '.') + 1));
            $nodes[$key] = ['key' => $key, 'source' => $source, 'from' => $this->sources[$key] ?? $this->provenance($key), 'members' => $members, 'children' => $children, 'uses' => $recipe instanceof Part ? $recipe->scaffold() : null];
        }

        return $nodes;
    }

    private function provenance(string $key): string
    {
        $source = $this->nodeSource($key);
        $packages = $this->packages($key);

        return $source === 'app' && $packages !== [] ? 'app (overrides '.implode(', ', $packages).')' : $source;
    }

    /** @return list<string> */
    private function packages(string $key): array
    {
        $packages = array_values(array_filter(array_keys($this->recipes[$key] ?? []), static fn (string $source): bool => $source !== 'app'));
        if (str_contains($key, '.')) {
            $parent = substr($key, 0, (int) strrpos($key, '.'));
            $packages = array_values(array_unique([...$packages, ...$this->packages($parent)]));
        }

        return $packages;
    }

    private function nodeSource(string $key): string
    {
        while (! isset($this->recipes[$key]) && str_contains($key, '.')) {
            $key = substr($key, 0, (int) strrpos($key, '.'));
        }
        $sources = $this->recipes[$key] ?? [];

        return isset($sources['app']) ? 'app' : (array_key_first($sources) ?? 'app');
    }

    /** @param array<string, Scaffold> $nodes
     * @param  list<string>  $references
     */
    private function flatten(string $key, Scaffold $recipe, array &$nodes, array $references): void
    {
        $nodes[$key] = $recipe;
        $parts = $recipe->parts();
        if ($recipe instanceof Part && $recipe->scaffold() !== null && ! in_array($recipe->scaffold(), $references, true)) {
            $source = $this->registered($recipe->scaffold());
            if ($source !== null) {
                $parts = [...$source->parts(), ...$parts];
                $references[] = $recipe->scaffold();
            }
        }
        foreach ($parts as $name => $part) {
            $path = $key.'.'.$name;
            $override = $this->registered($path);
            $this->flatten($path, $override instanceof Part ? $override : $part, $nodes, $references);
        }
    }

    /** @return array<string, string> */
    private function cycles(): array
    {
        $problems = [];
        $visit = function (string $name, array $chain) use (&$visit, &$problems): void {
            if (in_array($name, $chain, true)) {
                $cycle = [];
                $found = false;
                foreach ($chain as $node) {
                    $found = $found || $node === $name;
                    if ($found) {
                        $cycle[] = $node;
                    }
                }
                $cycle[] = $name;
                $message = 'Scaffolds include each other: '.implode(' → ', $cycle).'. '.implode(' and ', array_map(static fn (string $node): string => 'mod:'.$node, array_unique($cycle))).' are disabled. Remove one include().';
                foreach ($cycle as $node) {
                    $problems[$node] ??= $message;
                }

                return;
            }
            foreach ($this->registered($name)?->includes() ?? [] as $included) {
                $visit($included, [...$chain, $name]);
            }
        };
        foreach (array_keys($this->recipes) as $name) {
            $visit($name, []);
        }

        return $problems;
    }

    /** Validate only recipes: a broken scaffold never prevents the layout's commands from booting.
     * @param  array<string, Scaffold>  $overrides
     * @param  list<string>  $commands  existing command names, including aliases
     * @return array<string, Scaffold>
     */
    public function resolve(CompiledLayout $layout, string $layoutName, array $overrides = [], array $commands = []): array
    {
        $this->problems = $this->cycles();
        $this->sources = [];
        $resolved = [];
        $nodes = $this->all();
        foreach ($overrides as $key => $recipe) {
            $this->flatten($key, $recipe, $nodes, [$key]);
        }
        $names = array_unique([...array_keys($nodes), ...array_keys($this->recipes)]);
        foreach ($names as $name) {
            if (isset($this->problems[$name])) {
                continue;
            }
            if (str_contains($name, '.')) {
                $root = explode('.', $name)[0];
                $parent = substr($name, 0, (int) strrpos($name, '.'));
                $partName = substr($name, strlen($parent) + 1);
                $parentRecipe = $nodes[$parent] ?? null;
                $childParts = $parentRecipe?->parts() ?? [];
                if ($parentRecipe instanceof Part && $parentRecipe->scaffold() !== null) {
                    $childParts = [...($this->registered($parentRecipe->scaffold())?->parts() ?? []), ...$childParts];
                }
                if (! isset($nodes[$root]) || ! isset($childParts[$partName])) {
                    $this->problems[$name] = "Scaffold names can't contain dots; a dot means a part. There is no {$root} scaffold part for {$name} to belong to. Name it ".str_replace('.', '-', $name).", or declare the part on {$root}.";

                    continue;
                }
                if (isset($this->problems[$root])) {
                    $this->problems[$name] = $this->problems[$root];

                    continue;
                }
            }
            $sources = $this->recipes[$name] ?? [];
            $packages = array_values(array_filter(array_keys($sources), static fn (string $source): bool => $source !== 'app'));
            $override = $overrides[$name] ?? null;
            if ($override === null && ! isset($sources['app']) && count($packages) > 1) {
                $this->problems[$name] = "Scaffold [{$name}] is registered by ".implode(' and by ', $packages).", so mod:{$name} is disabled. Define {$name} in your app to use your own.";

                continue;
            }
            $recipe = $override ?? $nodes[$name] ?? $this->registered($name);
            if ($recipe === null) {
                continue;
            }
            $source = $override !== null ? 'layout' : (isset($sources['app']) ? 'app' : ($packages[0] ?? $this->nodeSource($name)));
            $this->sources[$name] = $override !== null ? 'layout' : $this->provenance($name);
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
            if ($recipe instanceof Part && $recipe->scaffold() !== null && ! isset($nodes[$recipe->scaffold()])) {
                $this->problems[$name] = "Scaffold [{$name}] uses missing scaffold [{$recipe->scaffold()}]. Define it before running mod:{$name}.";

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

        $this->resolvedNodes = $resolved;

        return $resolved;
    }

    /** @return array<string, Scaffold> Recipes accepted with the actual command registry. */
    public function resolved(): array
    {
        return $this->resolvedNodes;
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
