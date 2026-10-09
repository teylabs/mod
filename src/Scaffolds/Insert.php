<?php

namespace Tey\Mod\Scaffolds;

final readonly class Insert
{
    public function __construct(public string $into, public string $at, public string $stub) {}
}
