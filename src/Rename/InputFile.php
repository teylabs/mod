<?php

namespace Tey\Mod\Rename;

/** @internal Original bytes and permissions shared by every contributor. */
final readonly class InputFile
{
    public string $hash;

    public function __construct(public string $path, public string $bytes, public int $mode, public bool $historicalMigration = false)
    {
        $this->hash = hash('sha256', $bytes);
    }
}
