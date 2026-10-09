<?php

namespace Tey\Mod\Scaffolds;

/** A child recipe and the inserts its parent owns. */
final class Part extends Scaffold
{
    private ?string $uses = null;

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<Insert> */
    private array $inserts = [];

    /** @param array<string, mixed> $with */
    public function uses(string $scaffold, array $with = []): self
    {
        $this->uses = $scaffold;
        $this->values = $with;

        return $this;
    }

    public function inserts(string $into, string $at, string $stub): self
    {
        $this->inserts[] = new Insert($into, $at, $stub);

        return $this;
    }

    public function scaffold(): ?string
    {
        return $this->uses;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return list<Insert> */
    public function insertions(): array
    {
        return $this->inserts;
    }
}
