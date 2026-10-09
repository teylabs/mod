<?php

namespace Tey\Mod\Layout;

use Closure;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Placement\PlacementContext;

/**
 * One file type of a layout: where its artifacts go and how they are named.
 *
 * Reached through `->generates(..., using: fn (FileType $kind) => $kind->...)` for
 * what the named arguments do not cover; every method returns the kind.
 */
final class FileType
{
    private ?string $in = null;

    private ?string $root = null;

    private ?string $fallback = null;

    /** @var 'as-given'|'timestamped'|array{suffix: string}|array{fixed: string}|null */
    private string|array|null $name = null;

    private bool $file = false;

    private ?string $extension = null;

    private ?string $case = null;

    private string|false|null $command = null;

    /** @var list<string> */
    private array $aliases = [];

    private ?Stub $stub = null;

    private ?string $label = null;

    private ?int $priority = null;

    private ?bool $nested = null;

    private ?string $discover = null;

    /** @var list<string>|null */
    private ?array $except = null;

    /** @var (Closure(string, PlacementContext): string)|null */
    private ?Closure $place = null;

    /** @var list<string> */
    private array $reads = [];

    /**
     * @internal created by Layout::generates()
     */
    public function __construct(public readonly string $id) {}

    /**
     * Where the file type's artifacts go: "Models", "Http/Controllers/{area?}",
     * or "root:Path/{area}" to place them in another declared root.
     */
    public function in(string $path): self
    {
        $this->in = $path;

        return $this;
    }

    /** Place here under the same root when no placement is supplied. */
    public function ungrouped(string $path): self
    {
        $this->fallback = $path;

        return $this;
    }

    public function asGiven(): self
    {
        $this->name = 'as-given';

        return $this;
    }

    /**
     * The basename is the given name plus this suffix (Invoice → InvoiceController).
     */
    public function suffix(string $suffix): self
    {
        $this->name = ['suffix' => $suffix];

        return $this;
    }

    /**
     * Every artifact of the kind has this basename; its placement tells them apart.
     */
    public function fixed(string $basename): self
    {
        $this->name = ['fixed' => $basename];

        return $this;
    }

    /**
     * A file named <timestamp>_<name>, like a migration.
     */
    public function timestamped(): self
    {
        $this->name = 'timestamped';

        return $this;
    }

    /**
     * A plain file rather than a PHP class (a routes file, say).
     */
    public function extension(string $extension): self
    {
        $this->extension = $extension;
        $this->file = true;

        return $this;
    }

    public function case(string $case): self
    {
        $this->case = $case;

        return $this;
    }

    public function file(): self
    {
        $this->file = true;

        return $this;
    }

    /**
     * The artisan command that generates the kind; false for none. Defaults to mod:<kind>.
     */
    public function command(string|false $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Other names for the file type's command (`mod:data` for `mod:dto`, say); they run the same command.
     */
    public function aliases(string ...$names): self
    {
        foreach ($names as $name) {
            if (! in_array($name, $this->aliases, true)) {
                $this->aliases[] = $name;
            }
        }

        return $this;
    }

    /**
     * The noun the file type's command prints: "DTO [...] created successfully."
     * Without one, a generated class is named after the kind id.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The stub the file type's classes are generated from, with its variants and base.
     */
    public function stub(Stub $stub): self
    {
        $this->stub = $stub;

        return $this;
    }

    /**
     * Accept nested names ("Billing/Invoice"): the folders go below the file type's
     * own folder and the basename last, as native make:* does.
     */
    public function nested(bool $nested = true): self
    {
        $this->nested = $nested;

        return $this;
    }

    /**
     * Where discovery looks for this file type's classes: 'folder' (its own folder,
     * the default) or 'anywhere' below its dimension folders, skipping the
     * given folders (eligibility still decides).
     *
     * @param  string  $where  'folder' or 'anywhere'
     * @param  list<string>  $except  folders relative to the dimension folder, e.g. ['Tests', 'Database/Migrations']
     */
    public function discover(string $where, array $except = []): self
    {
        $this->discover = $where === 'anywhere' ? 'anywhere' : null;
        $this->except = $where === 'anywhere' ? $except : null;

        return $this;
    }

    /**
     * Breaks ties when two kinds could own the same class.
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Place artifacts with a closure instead of a path: it returns the
     * sub-namespace under the root. Such kinds generate but are never
     * recognized by discovery.
     *
     * @param  Closure(string, PlacementContext): string  $place
     * @param  list<string>  $reads  the placeholders the closure reads, e.g. ['area']
     */
    public function place(Closure $place, array $reads = []): self
    {
        $this->place = $place;
        $this->reads = $reads;

        return $this;
    }

    /**
     * @internal the root a Root closure declared the kind in
     */
    public function withinRoot(string $root): self
    {
        $this->root = $root;

        return $this;
    }

    /**
     * @internal
     *
     * @return array{in: ?string, fallback: ?string, root: ?string, name: 'as-given'|'timestamped'|array{suffix: string}|array{fixed: string}|null, file: bool, extension: ?string, case: ?string, command: string|false|null, aliases: list<string>, stub: ?Stub, label: ?string, priority: ?int, nested: ?bool, discover: ?string, except: list<string>|null, place: (Closure(string, PlacementContext): string)|null, reads: list<string>}
     */
    public function toArray(): array
    {
        return [
            'in' => $this->in,
            'fallback' => $this->fallback,
            'root' => $this->root,
            'name' => $this->name,
            'file' => $this->file,
            'extension' => $this->extension,
            'case' => $this->case,
            'command' => $this->command,
            'aliases' => $this->aliases,
            'stub' => $this->stub,
            'label' => $this->label,
            'priority' => $this->priority,
            'nested' => $this->nested,
            'discover' => $this->discover,
            'except' => $this->except,
            'place' => $this->place,
            'reads' => $this->reads,
        ];
    }
}
