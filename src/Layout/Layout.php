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
 * the values in --in=A/B, and each one is also an option of the mod:*
 * commands that read it (--area=, renamed with ->placementOption()). Declaring a kind, root or relation id again
 * overrides the arguments given and keeps the rest, so built-in layouts
 * extend the same way. Everything is checked when the layout is compiled.
 */
final class Layout
{
    /** @var array<string, array{namespace: ?string, path: string}> */
    private array $roots = [];

    /** @var array<string, Kind> */
    private array $kinds = [];

    /** @var array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null, name: string|array<string, string>|null, policy: string|RelationPolicy|null}> */
    private array $relations = [];

    /** @var list<string> */
    private array $excluded = [];

    private bool $commands = true;

    /** @var array<string, string> placeholder name ('' for the layout's only one) → option name */
    private array $placementOptions = [];

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
     * `nested: true` accepts nested names ("Billing/Invoice"); `discoverAnywhere: true`
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
        ?bool $discoverAnywhere = null,
        ?array $except = null,
        ?Closure $using = null,
        ?string $fallback = null,
    ): self {
        $this->guard();

        $kind = $this->kinds[$id] ??= new Kind($id);

        if ($fallback !== null) {
            $kind->fallback($fallback);
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

        if ($discoverAnywhere !== null || $except !== null) {
            if ($discoverAnywhere === false) {
                throw new ModException("Kind [{$id}]: discoverAnywhere cannot be false; leave it out to discover the kind in its own folder only.");
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
     * @param  string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null  $scope  'same' (default), the placeholders the target keeps, e.g. ['area'], or ['keep' => [...], 'nested' => 'drop'] to drop the source's nested folders; 'name' => 'operation' fills a missing target dimension from the source stem
     * @param  string|array<string, string>|null  $name  how the target's name derives from the source's: 'explicit' (the caller always names it), or a map of 'strip-suffix' (removed from the source name first), 'prefix' and 'suffix' (added around it); the target kind's own name policy (suffix()/fixed()) still applies afterwards, so a controller→request relation needs no 'Request' suffix when the request kind declares one
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

        $this->relations[$id] = [
            'from' => $from ?? $relation['from'],
            'to' => $to ?? $relation['to'],
            'scope' => $scope ?? $relation['scope'],
            'name' => $name ?? $relation['name'],
            'policy' => $policy ?? $relation['policy'],
        ];

        return $this;
    }

    /**
     * Namespaces ('App\\Support\\') or paths ('app/Support') inside a declared
     * root that no kind owns: mod never places anything there, reverse mapping
     * reports classes below them as not owned, and discovery skips them. Use
     * it for hand-maintained corners of a root that would otherwise match a
     * nested or discover-anywhere kind.
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
     * Rename the placement option of one placeholder, or of the layout's
     * only placeholder when none is named: `->placementOption('area')` turns
     * --module= into --area= on a layout placing by {module};
     * `->placementOption('topic', '{feature}')` renames one of several.
     */
    public function placementOption(string $option, ?string $placeholder = null): self
    {
        $this->guard();

        $this->placementOptions[$placeholder === null ? '' : trim($placeholder, '{}+?')] = $option;

        return $this;
    }

    /**
     * Register no mod:* commands for this layout (a host with its own artisan
     * catalog). Kinds keep their command names for the host to dispatch by,
     * and several kinds may then share one (a host that places the same
     * command's output in different roots).
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
     * @return array{roots: array<string, array{namespace: ?string, path: string}>, kinds: array<string, Kind>, relations: array<string, array{from: ?string, to: ?string, scope: string|list<string>|array{keep?: list<string>, nested?: 'keep'|'drop', name?: string}|null, name: string|array<string, string>|null, policy: string|RelationPolicy|null}>, excluded: list<string>, commands: bool, placement_options: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'roots' => $this->roots,
            'kinds' => $this->kinds,
            'relations' => $this->relations,
            'excluded' => $this->excluded,
            'commands' => $this->commands,
            'placement_options' => $this->placementOptions,
        ];
    }

    private function guard(): void
    {
        if ($this->sealed) {
            throw new ModException("Layout [{$this->name}] is already in use, so this change would never apply. Define layouts in a service provider's register() or boot().");
        }
    }
}
