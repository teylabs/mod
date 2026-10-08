<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledLayout;

/**
 * Resolves an artifact request to its identity under one preset. Pure: no filesystem.
 *
 * @internal
 */
final readonly class PlacementResolver
{
    public function __construct(private CompiledLayout $preset) {}

    public function resolve(ArtifactRequest $request): ResolvedArtifact
    {
        $kind = $this->preset->kind($request->kindId);

        return $this->preset->rule($kind->id)->place($kind, $request->name, $request->context, $request->attributes);
    }
}
