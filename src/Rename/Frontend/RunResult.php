<?php

namespace Tey\Mod\Rename\Frontend;

/** @internal */
final readonly class RunResult
{
    /** @param array<string, string> $dependencies */
    public function __construct(public ?string $stdout, public string $diagnostic = '', public array $dependencies = []) {}
}
