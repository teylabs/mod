<?php

namespace Tey\Mod\Scaffolds;

/** @internal Stands in for a member's alias when its `when:` kept it out of the plan. */
final readonly class SkippedMember
{
    public function __construct(public string $alias, public string $reason) {}
}
