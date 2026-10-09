<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Tey\Mod\Exceptions\GenerationRefused;

/** A recipe of file types, placed by the active layout.
 * @api
 */
class Scaffold
{
    /** @var array<string, Question> */
    private array $questions = [];

    /** @var array<string, Part> */
    private array $parts = [];

    /** @var array<string, string> */
    private array $repetitions = [];

    /** @var list<string> */
    private array $includes = [];

    /** @var array<string, Member> */
    private array $members = [];

    /** @var array<string, bool> aliases copied by include(), replaceable once */
    private array $inherited = [];

    /** @var list<string> */
    private array $duplicates = [];

    /** @var list<string> */
    private array $errors = [];

    /** @param (Closure(string): ?self)|null $resolve */
    public function __construct(private readonly ?Closure $resolve = null) {}

    /** @param array<array-key, mixed> $options flags passed to this file type's generator * @api
     */
    public function makes(string $fileType, ?string $name = null, ?string $as = null, ?string $stub = null, array $options = [], bool $ungrouped = false, ?string $group = null, ?string $existing = null): static
    {
        if ($ungrouped && $group !== null) {
            throw GenerationRefused::because('A scaffold member cannot set both ungrouped: true and group:. Choose one placement.');
        }
        if ($existing !== null && $existing !== 'keep') {
            throw GenerationRefused::because("A scaffold member existing: policy must be 'keep'. Remove it to use the scaffold collision choice.");
        }
        $alias = $as ?? $fileType;
        if (isset($this->members[$alias]) && ! isset($this->inherited[$alias])) {
            $this->duplicates[] = $alias;
        }
        unset($this->inherited[$alias]);
        $this->members[$alias] = new Member($fileType, $name, $stub, $options, $ungrouped, $group, $existing);

        return $this;
    }

    /** Copy the other recipe as it stands now. * @api
     */
    public function include(string $name): static
    {
        $this->includes[] = $name;
        $source = ($this->resolve) !== null ? ($this->resolve)($name) : null;
        if ($source === null) {
            $this->errors[] = "Scaffold [{$name}] is not defined. Define it before calling include('{$name}').";

            return $this;
        }
        foreach ($source->members as $alias => $member) {
            $this->members[$alias] = $member;
            $this->inherited[$alias] = true;
        }
        $this->questions = [...$this->questions, ...$source->questions];
        foreach ($source->parts as $key => $part) {
            $this->parts[$key] = clone $part;
        }
        $this->repetitions = [...$this->repetitions, ...$source->repetitions];
        array_push($this->duplicates, ...$source->duplicates);
        array_push($this->errors, ...$source->errors);

        return $this;
    }

    /** @param array<array-key, string> $options * @api
     */
    public function asks(string $name, string $type = 'text', mixed $default = null, ?string $label = null, array $options = []): static
    {
        $this->questions[$name] = new Question($name, $type, $default, $label, $options);

        return $this;
    }

    /** @api */
    public function each(string $name, string $part): static
    {
        $this->repetitions[$name] = $part;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $with
     * @param  (Closure(Part): mixed)|null  $configure
     *
     * @api
     */
    public function part(string $name, string|Closure|null $uses = null, array $with = [], ?Closure $configure = null): static
    {
        if (str_contains($name, '.')) {
            throw GenerationRefused::because("Part names cannot contain dots. Declare {$name} as nested parts.");
        }
        if ($uses instanceof Closure) {
            $configure = $uses;
            $uses = null;
        }
        $part = new Part($this->resolve);
        if ($uses !== null) {
            $part->uses($uses, $with);
        }
        if ($configure !== null) {
            $configure($part);
        }
        $this->parts[$name] = $part;

        return $this;
    }

    /** @return array<string, Part> */
    public function parts(): array
    {
        return $this->parts;
    }

    /** @return array<string, Question> */
    public function questions(): array
    {
        return $this->questions;
    }

    /** @return array<string, string> */
    public function repetitions(): array
    {
        return $this->repetitions;
    }

    /** @return list<string> */
    public function includes(): array
    {
        return $this->includes;
    }

    /** @internal Combine an inline part with its referenced recipe. */
    public function overlay(self $part): void
    {
        $this->questions = [...$this->questions, ...$part->questions];
        $this->members = [...$this->members, ...$part->members];
        $this->parts = [...$this->parts, ...$part->parts];
        $this->repetitions = [...$this->repetitions, ...$part->repetitions];
    }

    /** @return array<string, Member> */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function duplicates(): array
    {
        return array_values(array_unique($this->duplicates));
    }
}
