<?php

namespace Tey\Mod\Rename;

/** @internal Byte coordinates always refer to the original input. */
final readonly class Edit
{
    public function __construct(public string $file, public int $offset, public string $before, public string $after, public string $category, public int $line) {}
}
