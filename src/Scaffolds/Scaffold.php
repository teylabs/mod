<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Tey\Mod\Exceptions\ModException;

/** A recipe of file types, placed by the active layout. */
final class Scaffold
{
    /** @var array<string, Member> */
    private array $members = [];

    /** @var array<string, bool> aliases copied by include(), replaceable once */
    private array $inherited = [];

    /** @var list<string> */
    private array $duplicates = [];

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
        $source = ($this->resolve) !== null ? ($this->resolve)($name) : null;
        if ($source === null) {
            throw new ModException("Scaffold [{$name}] is not defined. Define it before calling include('{$name}').");
        }
        foreach ($source->members as $alias => $member) {
            $this->members[$alias] = $member;
            $this->inherited[$alias] = true;
        }
        array_push($this->duplicates, ...$source->duplicates);

        return $this;
    }

    /** @return array<string, Member> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return list<string> */
    public function duplicates(): array
    {
        return array_values(array_unique($this->duplicates));
    }
}
