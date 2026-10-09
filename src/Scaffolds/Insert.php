<?php

namespace Tey\Mod\Scaffolds;

/** @internal */
final readonly class Insert
{
    public function __construct(public string $into, public string $at, public string $stub) {}
}
