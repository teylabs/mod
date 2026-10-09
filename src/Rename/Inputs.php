<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Layout\CompiledLayout;

/** @internal Snapshot passed unchanged to contributors and the eventual executor. */
final readonly class Inputs
{
    /** @param list<ClusterMember> $members
     * @param  array<string, InputFile>  $files
     * @param  list<string>  $roots
     * @param  array<string, string>  $dependencies  Path => byte hash, including recipes/templates/parser metadata.
     * @param  list<string>  $membership
     */
    public function __construct(public string $basePath, public Request $request, public CompiledLayout $layout, public array $members, public array $files, public array $roots, public array $dependencies, public array $membership, public GitState $git, public string $definitionHash) {}

    public function afterPath(string $file): string
    {
        foreach ($this->members as $member) {
            if ($member->old->path() === $file && $member->definition->existing !== 'keep' && ! $member->old->fileType->isTimestamped()) {
                return $member->new->path();
            }
        }

        return $file;
    }
}
