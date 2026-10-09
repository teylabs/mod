<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Plans\Plan;

/** @internal Validated in-memory output; it confers no permission to execute. */
final readonly class Result
{
    /** @param array<string, string> $bodies Original path => composed output bytes.
     * @param  list<GeneratedFile>  $generated
     */
    public function __construct(public Plan $plan, public ?Inputs $inputs = null, public array $bodies = [], public array $generated = []) {}
}
