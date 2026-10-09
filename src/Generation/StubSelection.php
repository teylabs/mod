<?php

namespace Tey\Mod\Generation;

/** @internal A read-only selection shared by generation and mod:list. */
final readonly class StubSelection
{
    public function __construct(public ?string $file, public string $source, public ?StubChoice $choice = null) {}
}
