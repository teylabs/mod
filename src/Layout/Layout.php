<?php

namespace Tey\Mod\Layout;

use Closure;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationPolicy;

/**
 * A named layout, defined (or extended) as one fluent chain:
 *
 *     Mod::layout('reporting')
 *         ->root('app', 'App\\', 'app', fn (Root $root) => $root
 *             ->kind('model', in: 'Reports/{area}/Models')
 *             ->kind('controller', in: 'Reports/{area}/Controllers', suffix: 'Controller'))
 *         ->root('factories', 'Database\\Factories\\', 'database/factories')
 *         ->kind('factory', in: 'factories:{area}', suffix: 'Factory')
 *         ->relation('factory', from: 'model', to: 'factory')
 *         ->exclude('App\\Support\\');
 *
 * Placeholders such as {area} or {area?} are the layout's placement
 * dimensions, in order of first appearance; that order is the order of
 * the values in --in=A/B. Declaring a kind, root or relation id again
 * overrides the arguments given and keeps the rest, so built-in layouts
 * extend the same way. Everything is checked when the layout is compiled.
 */
final class Layout
{
    /** @var array<string, array{namespace: ?string, path: string}> */
    private array $roots = [];

    /** @var array<string, Kind> */
    private array $kinds = [];

    /** @var array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop'}|null, name: string|array<string, string>|null, policy: string|RelationPolicy|null}> */
    private array $relations = [];

    /** @var list<string> */
    private array $excluded = [];

    private bool $commands = true;

    private bool $sealed = false;

    /**
     * @internal created by LayoutRegistry::layout()
     */
    public function __construct(public readonly string $name) {}

    /**
     * A namespace ↔ folder mapping kinds are placed in; a null namespace makes a root for plain files.
     *
     * @param  (Closure(Root): mixed)|null  $kinds  declares kinds that live in this root
     */
    public function root(string $name, ?string $namespace, string $path, ?Closure $kinds = null): self
    {
        $this->guard();

        $this->roots[$name] = ['namespace' => $namespace === '' ? null : $namespace, 'path' => $path];

        if ($kinds !== null) {
            $kinds(new Root($name, $this));
        }

        return $this;
    }

    /**
     * An artifact kind. `in:` is its path below the root ("Http/Controllers/{area?}",
     * or "root:Path" for another root); the generating command defaults to mod:<id>.
     * `nested: true` accepts nested names ("Billing/Invoice"); `discover: 'anywhere'`
     * (with `except: [...]`) widens discovery to every file below the kind's
     * dimension folders. A `{name+}` placeholder spans one or more folders.
     *
     * @param  list<string>|null  $except  folders discovery skips, relative to the dimension folder
     * @param  (Closure(Kind): mixed)|null  $using  for what the named arguments do not cover
     */
    public function kind(
        string $id,
        ?string $in = null,
        ?string $suffix = null,
        ?string $fixed = null,
        ?bool $timestamped = null,
        string|false|null $command = null,
        ?int $priority = null,
        ?bool $nested = null,
        ?string $discover = null,
        ?array $except = null,
        ?Closure $using = null,
    ): self {
        $this->guard();

        $kind = $this->kinds[$id] ??= new Kind($id);

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

        if ($discover !== null || $except !== null) {
            if ($discover !== null && $discover !== 'anywhere') {
                throw new ModException("Kind [{$id}]: discover must be 'anywhere' when given.");
            }

            $kind->discoverAnywhere($except ?? []);
        }

        if ($using !== null) {
            $using($kind);
        }

        return $this;
    }

    /**
     * A relation from one kind to another (mod:model --factory follows `from: 'model', to: 'factory'`).
     *
     * @param  string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop'}|null  $scope  'same' (default), the placeholders the target keeps, e.g. ['area'], or ['keep' => [...], 'nested' => 'drop'] to drop the source's nested folders
     * @param  string|array<string, string>|null  $name  'explicit', or ['prefix' => 'Store', 'suffix' => ..., 'strip-suffix' => ...]
     * @param  string|RelationPolicy|null  $policy  'generate' (default), 'reference' or 'none'
     */
    public function relation(
        string $id,
        ?string $from = null,
        ?string $to = null,
        string|array|null $scope = null,
        string|array|null $name = null,
        string|RelationPolicy|null $policy = null,
    ): self {
        $this->guard();

        $relation = $this->relations[$id] ?? ['from' => null, 'to' => null, 'scope' => null, 'name' => null, 'policy' => null];

        foreach (['from' => $from, 'to' => $to, 'scope' => $scope, 'name' => $name, 'policy' => $policy] as $key => $value) {
            if ($value !== null) {
                $relation[$key] = $value;
            }
        }

        $this->relations[$id] = $relation;

        return $this;
    }

    /**
     * Namespaces ('App\\Support\\') or paths ('app/Support') inside a declared root that no kind owns.
     */
    public function exclude(string ...$excluded): self
    {
        $this->guard();

        foreach ($excluded as $entry) {
            if (! in_array($entry, $this->excluded, true)) {
                $this->excluded[] = $entry;
            }
        }

        return $this;
    }

    /**
     * Register no mod:* commands for this layout (a host with its own artisan catalog).
     */
    public function withoutCommands(): self
    {
        $this->guard();

        $this->commands = false;

        return $this;
    }

    /**
     * @throws InvalidLayout
     */
    public function compile(): Preset
    {
        return (new LayoutCompiler($this))->compile();
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
     *
     * @return array{roots: array<string, array{namespace: ?string, path: string}>, kinds: array<string, Kind>, relations: array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop'}|null, name: string|array<string, string>|null, policy: string|RelationPolicy|null}>, excluded: list<string>, commands: bool}
     */
    public function toArray(): array
    {
        return [
            'roots' => $this->roots,
            'kinds' => $this->kinds,
            'relations' => $this->relations,
            'excluded' => $this->excluded,
            'commands' => $this->commands,
        ];
    }

    private function guard(): void
    {
        if ($this->sealed) {
            throw new ModException("Layout [{$this->name}] is already in use, so this change would never apply. Define layouts in a service provider's register() or boot().");
        }
    }
}
