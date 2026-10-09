<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Scaffolds\Member;

/** @internal Both evaluations use the frozen source recipe. */
final readonly class ClusterMember
{
    /** @param array<string, string> $oldIdentity
     * @param  array<string, string>  $newIdentity
     */
    public function __construct(public string $alias, public Member $definition, public ResolvedArtifact $old, public ResolvedArtifact $new, public array $oldIdentity, public array $newIdentity, public CompiledLayout $oldLayout, public CompiledLayout $newLayout) {}
}
