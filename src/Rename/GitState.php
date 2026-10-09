<?php

namespace Tey\Mod\Rename;

/** @internal Git's own worktree-specific metadata paths, not guessed .git paths. */
final readonly class GitState
{
    /** @param list<string> $paths */
    public function __construct(public ?string $root, public ?string $directory, public ?string $index, public string $status, public ?string $indexHash, public array $paths) {}

    public function clean(): bool
    {
        return $this->root !== null && $this->status === '';
    }
}
