<?php

namespace Tey\Mod\Layout;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Str;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\TemplateCatalog;

/**
 * A named layout, defined (or extended) as one fluent chain:
 *
 *     Mod::layout('reporting')
 *         ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
 *             ->generates('model', in: 'Reports/{area}/Models')
 *             ->generates('controller', in: 'Reports/{area}/Controllers', suffix: 'Controller'))
 *         ->mounts('factories', 'Database\\Factories\\', 'database/factories')
 *         ->generates('factory', in: 'factories:{area}', suffix: 'Factory')
 *         ->relates('model', to: 'factory', as: 'model-factory')
 *         ->excludes('App\\Support\\');
 *
 * Placeholders such as {area} or {area?} are the layout's placement
 * dimensions, in order of first appearance; that order is the order of
 * the values in --in=A/B, and each one is also an option of the mod:*
 * commands that read it (--area=, named by ->path()). Declaring a file type, root or relation id again
 * overrides the arguments given and keeps the rest, so built-in layouts
 * can be customized the same way. Everything is checked when the layout is compiled.
 */
final class Layout
{
    /** @var array<string, array{namespace: ?string, path: string}> */
    private array $roots = [];

    /** @var array<string, FileType> */
    private array $kinds = [];

    /** @var array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null, name: string|array<string, string>|null, mode: string|RelationMode|null}> */
    private array $relations = [];

    /** @var list<string> */
    private array $excluded = [];

    /** @var array<string, Scaffold> */
    private array $scaffoldRecipes = [];

    private bool $commands = true;

    private ?string $groupPath = null;

    private bool $declaredPath = false;

    private bool $touched = false;

    /** @var list<string> */
    private array $chainErrors = [];

    /** @var list<string> null dimension is stored as an empty string */
    private array $nesting = [];

    private ?string $parent = null;

    private bool $sealed = false;

    /**
     * @internal created by LayoutRegistry::layout()
     */
    public function __construct(public readonly string $name, private readonly ?LayoutRegistry $registry = null) {}

    /**
     * A namespace ↔ folder mapping file types are placed in; a null namespace makes a root for plain files.
     *
     * @param  (Closure(Root): mixed)|null  $fn  declares file types that live in this root
     */
    public function mounts(string $name, ?string $namespace, string $path, ?Closure $fn = null): self
    {
        $this->guard();

        $this->roots[$name] = ['namespace' => $namespace === '' ? null : $namespace, 'path' => $path];

        if ($fn !== null) {
            $fn(new Root($name, $this));
        }

        return $this;
    }

    /**
     * A file type. `in:` is its path below the root ("Http/Controllers/{area?}",
     * or "root:Path" for another root); the generating command defaults to mod:<id>.
     * `nested: true` accepts nested names ("Billing/Invoice"); `discover: 'anywhere'`
     * (with `discoverExcept: [...]`) widens discovery to every file below the
     * file type's dimension folders, and `discover: 'folder'` (the default) keeps it
     * to the file type's own folder. `ungrouped:` is where the kind goes when no
     * placement is given. A `{name+}` placeholder spans one or more folders.
     * `aliases:` gives the command other names; `stub:` the stub its classes
     * are generated from (with variants and a base, see Stub); `label:` the
     * noun its command prints ("DTO [...] created successfully.").
     *
     * @param  string|null  $discover  where discovery looks for the file type's classes: 'folder' or 'anywhere'
     * @param  list<string>|null  $discoverExcept  folders discovery skips, relative to the dimension folder
     * @param  (Closure(FileType): mixed)|null  $using  for what the named arguments do not cover
     * @param  list<string>|null  $aliases  other names for the file type's command
     */
    public function generates(
        string $id,
        ?string $in = null,
        ?string $suffix = null,
        ?string $fixed = null,
        ?bool $timestamped = null,
        string|false|null $command = null,
        ?int $priority = null,
        ?bool $nested = null,
        ?string $discover = null,
        ?array $discoverExcept = null,
        ?Closure $using = null,
        ?string $ungrouped = null,
        ?array $aliases = null,
        ?Stub $stub = null,
        ?string $label = null,
    ): self {
        $this->guard();

        $kind = $this->kinds[$id] ??= new FileType($id);

        if ($ungrouped !== null) {
            $kind->ungrouped($ungrouped);
        }

        if ($in !== null) {
            $kind->in($in);
        }

        if ($suffix !== null) {
            $kind->suffix($suffix);
        }

        if ($fixed !== null) {
            $kind->fixed($fixed);
        }

        if ($timestamped === true) {
            $kind->timestamped();
        } elseif ($timestamped === false) {
            $kind->asGiven();
        }

        if ($command !== null) {
            $kind->command($command);
        }

        if ($priority !== null) {
            $kind->priority($priority);
        }

        if ($nested !== null) {
            $kind->nested($nested);
        }

        if ($discover !== null && ! in_array($discover, ['folder', 'anywhere'], true)) {
            throw new ModException("File type [{$id}]: discover must be 'folder' or 'anywhere'.");
        }

        if ($discoverExcept !== null && $discover !== 'anywhere') {
            throw new ModException("File type [{$id}]: discoverExcept needs discover: 'anywhere'.");
        }

        if ($discover !== null) {
            $kind->discover($discover, $discoverExcept ?? []);
        }

        if ($aliases !== null) {
            $kind->aliases(...$aliases);
        }

        if ($stub !== null) {
            $kind->stub($stub);
        }

        if ($label !== null) {
            $kind->label($label);
        }

        if ($using !== null) {
            $using($kind);
        }

        return $this;
    }

    /**
     * A relation from one file type to another (mod:model --factory follows `from: 'model', to: 'factory'`).
     *
     * @param  string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null  $scope  'same' (default), the placeholders the target keeps, e.g. ['area'], or ['keep' => [...], 'nested' => 'drop'] to drop the source's nested folders; 'name' => 'operation' fills a missing target dimension from the source stem
     * @param  string|array<string, string>|null  $name  how the target's name derives from the source's: 'explicit' (the caller always names it), or a map of 'strip-suffix' (removed from the source name first), 'prefix' and 'suffix' (added around it); the target file type's own name policy (suffix()/fixed()) still applies afterwards, so a controller→request relation needs no 'Request' suffix when the request kind declares one
     * @param  string|RelationMode|null  $mode  'generate' (default), 'reference' or 'none'
     */
    public function relates(
        string $from,
        string $to,
        string|array|null $scope = null,
        string|array|null $name = null,
        string|RelationMode|null $mode = null,
        ?string $as = null,
    ): self {
        $this->guard();
        $id = $as ?? $from.'-'.$to;
        $relation = $this->relations[$id] ?? ['from' => null, 'to' => null, 'scope' => null, 'name' => null, 'mode' => null];
        $this->relations[$id] = [
            'from' => $from,
            'to' => $to,
            'scope' => $scope ?? $relation['scope'],
            'name' => $name ?? $relation['name'],
            'mode' => $mode ?? $relation['mode'],
        ];

        return $this;
    }

    /**
     * Namespaces ('App\\Support\\') or paths ('app/Support') inside a declared
     * root that no file type owns: mod never places anything there, reverse mapping
     * reports classes below them as not owned, and discovery skips them. Use
     * it for hand-maintained corners of a root that would otherwise match a
     * nested or discover-anywhere kind.
     */
    public function excludes(string ...$excluded): self
    {
        $this->guard();

        foreach ($excluded as $entry) {
            if (! in_array($entry, $this->excluded, true)) {
                $this->excluded[] = $entry;
            }
        }

        return $this;
    }

    /** Where groups live, relative to the project root (absolute paths also work). */
    public function path(string $path): self
    {
        $this->guard();
        $this->groupPath = $path;
        $this->declaredPath = true;

        return $this;
    }

    /** Copy one parent as it stands now. This must be the first call. */
    public function extends(string $parent): self
    {
        if ($this->sealed) {
            $this->guard();
        }
        if ($this->touched || $this->parent !== null) {
            $this->chainErrors[] = "extends('{$parent}') must come first: Mod::layout('{$this->name}')->extends('{$parent}')->generates(…).";

            return $this;
        }
        $this->guard();
        $registry = $this->registry ?? new LayoutRegistry;
        if ($parent === $this->name || ! $registry->has($parent)) {
            $this->chainErrors[] = "Parent layout [{$parent}] is not defined. Define it before calling extends().";

            return $this;
        }
        $source = $registry->layout($parent);
        $this->roots = $source->roots;
        $this->kinds = array_map(static fn (FileType $type): FileType => clone $type, $source->kinds);
        $this->relations = $source->relations;
        $this->excluded = $source->excluded;
        $this->commands = $source->commands;
        $this->scaffoldRecipes = $source->scaffoldRecipes;
        $this->groupPath = $source->groupPath;
        $this->declaredPath = $source->declaredPath;
        $this->nesting = $source->nesting;
        $this->chainErrors = $source->chainErrors;
        $this->parent = $parent;
        if (! $this->declaredPath && $this->groupPath !== null) {
            $token = Str::singular($this->name);
            if ($token === $this->name) {
                $this->groupPath = null;
                $this->chainErrors[] = "The layout name [{$this->name}] does not name a group. Declare ->path('app/{group}').";
            } else {
                preg_match('/\{(\w+)[+?]*\}/', $this->groupPath, $matches);
                if (isset($matches[1])) {
                    $old = $matches[1];
                    $rename = static fn (string $value): string => str_replace(['{'.$old, '@'.$old], ['{'.$token, '@'.$token], $value);
                    $this->groupPath = $rename($this->groupPath);
                    foreach ($this->kinds as $type) {
                        $in = $type->toArray()['in'];
                        if ($in !== null) {
                            $type->in($rename($in));
                        }
                    }
                }
            }
        }

        return $this;
    }

    /** Allow slash or dot separated group values, for one dimension. */
    public function allowsNesting(?string $dimension = null): self
    {
        $this->guard();
        $this->nesting[] = $dimension ?? '';

        return $this;
    }

    /** @internal built-ins with name-derived tokens keep a movable default path. */
    public function defaultPath(string $path): void
    {
        $this->groupPath = $path;
    }

    /** @internal registry initialization is not part of the user's chain. */
    public function beginChain(): void
    {
        $this->touched = false;
    }

    /**
     * Removed methods have no compatibility aliases.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): never
    {
        throw new BadMethodCallException('Method '.self::class."::{$method} does not exist.");
    }

    /**
     * Register no mod:* commands for this layout (a host with its own artisan
     * catalog). File types keep their command names for the host to dispatch by,
     * and several file types may then share one (a host that places the same
     * command's output in different roots).
     */
    /** @param Closure(Scaffold): mixed $recipe */
    public function scaffolds(string $name, Closure $recipe): self
    {
        $this->guard();
        $this->scaffoldRecipes[$name] = ($this->registry?->scaffoldRegistry() ?? new ScaffoldRegistry)->build($recipe);

        return $this;
    }

    /**
     * @internal
     *
     * @return array<string, Scaffold>
     */
    public function scaffoldRecipes(): array
    {
        return $this->scaffoldRecipes;
    }

    public function withoutCommands(): self
    {
        $this->guard();

        $this->commands = false;

        return $this;
    }

    /**
     * @throws InvalidLayout
     */
    public function compile(?TemplateCatalog $templates = null): CompiledLayout
    {
        return (new LayoutCompiler($this, $templates))->compile();
    }

    /**
     * @internal the layout is in use; later changes would never apply
     */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * @internal
     */
    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * @internal exclude the folders generated bases go in (app/Support/Data,
     * ...) when they lie inside a root, so a base is never taken for a group
     * or a file type's class
     */
    public function reserveBaseFolders(StubRegistry $stubs, string $basesPath): void
    {
        $folders = [];

        foreach ($this->kinds as $id => $kind) {
            $base = $stubs->resolve($id, $kind->toArray()['stub'])?->generatedBase();

            if ($base !== null && ! $base->inFileTypeRoot) {
                $folders[] = Path::join($basesPath, $base->in);
            }
        }

        foreach (array_unique($folders) as $folder) {
            foreach ($this->roots as $root) {
                if ($root['namespace'] !== null && Path::relative($root['path'], $folder) !== null) {
                    $this->excludes($folder);

                    break;
                }
            }
        }
    }

    /**
     * @internal
     *
     * @return array{roots: array<string, array{namespace: ?string, path: string}>, kinds: array<string, FileType>, relations: array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null, name: string|array<string, string>|null, mode: string|RelationMode|null}>, excluded: list<string>, commands: bool, path: ?string, nesting: list<string>, errors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'roots' => $this->roots,
            'kinds' => $this->kinds,
            'relations' => $this->relations,
            'excluded' => $this->excluded,
            'commands' => $this->commands,
            'path' => $this->groupPath,
            'nesting' => $this->nesting,
            'errors' => $this->chainErrors,
        ];
    }

    /** @internal The parent named by extends(), for mod:list. */
    public function parentName(): ?string
    {
        return $this->parent;
    }

    private function guard(): void
    {
        $this->touched = true;
        if ($this->sealed) {
            throw new ModException("Layout [{$this->name}] is already in use, so this change would never apply. Define layouts in a service provider's register() or boot().");
        }
    }
}
