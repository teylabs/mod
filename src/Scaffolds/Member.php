<?php

namespace Tey\Mod\Scaffolds;

/** @internal one file type in a scaffold recipe */
final readonly class Member
{
    /** @param array<array-key, mixed> $options */
    public function __construct(
        public string $fileType,
        public ?string $name = null,
        public ?string $stub = null,
        public array $options = [],
        public bool $ungrouped = false,
        public ?string $group = null,
        public ?string $existing = null,
    ) {}
}
