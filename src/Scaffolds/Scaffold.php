<?php

namespace Tey\Mod\Scaffolds;

use Closure;

/** A recipe of file types, placed by the active layout. */
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

    /** @param array<array-key, mixed> $options flags passed to this file type's generator */
    public function makes(string $fileType, ?string $name = null, ?string $as = null, ?string $stub = null, array $options = []): self
    {
        $alias = $as ?? $fileType;
        if (isset($this->members[$alias]) && ! isset($this->inherited[$alias])) {
            $this->duplicates[] = $alias;
        }
        unset($this->inherited[$alias]);
        $this->members[$alias] = new Member($fileType, $name, $stub, $options);

        return $this;
    }

    /** Copy the other recipe as it stands now. */
    public function include(string $name): self
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

    /** @param array<array-key, string> $options */
    public function asks(string $name, string $type = 'text', mixed $default = null, ?string $label = null, array $options = []): static
    {
        $this->questions[$name] = new Question($name, $type, $default, $label, $options);

        return $this;
    }

    public function each(string $name, string $part): static
    {
        $this->repetitions[$name] = $part;

        return $this;
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
