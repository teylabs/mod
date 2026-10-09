<?php

namespace Tey\Mod\Rename;

/** @internal Explicit recipe history; a preview is never an execution token. */
final readonly class Request
{
    /** @param array<string, mixed> $answers */
    public function __construct(public ?string $old, public ?string $new, public ?string $scaffold, public array $answers = [], public bool $tableMigration = false, public bool $recover = false, public bool $yes = false, public bool $interactive = false) {}
}
