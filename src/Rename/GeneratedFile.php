<?php

namespace Tey\Mod\Rename;

/** @internal A complete optional new output; contributors never write it. */
final readonly class GeneratedFile
{
    /** @param array<string, mixed> $identity */
    public function __construct(public string $path, public string $bytes, public int $mode, public string $alias, public string $type, public array $identity = [], public ?string $timestamp = null) {}
}
