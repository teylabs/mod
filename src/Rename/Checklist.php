<?php

namespace Tey\Mod\Rename;

/** @internal Advisory findings never authorise an edit. */
final readonly class Checklist
{
    public function __construct(public string $file, public int $line, public string $category, public string $message, public ?string $suggestion = null) {}
}
