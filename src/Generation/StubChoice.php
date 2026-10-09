<?php

namespace Tey\Mod\Generation;

/**
 * @internal what a Stub resolved to for one generation: the file, the base class
 * to extend (or the base to generate), and the line that says which branch was taken.
 */
final readonly class StubChoice
{
    public function __construct(
        public string $file,
        public ?string $base = null,
        public ?GeneratedBase $generatedBase = null,
        public ?string $message = null,
        public ?string $package = null,
    ) {}

    public function withBase(string $base): self
    {
        return new self($this->file, $base, $this->generatedBase, $this->message, $this->package);
    }
}
