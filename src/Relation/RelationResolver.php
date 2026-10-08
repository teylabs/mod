<?php

namespace Tey\Mod\Relation;

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementResolver;

/**
 * Resolves a relation from an already resolved source artifact to its target identity.
 *
 * Whether the target exists on disk is the generator's concern; this stays pure.
 * The source's nested folders carry over to the target unless the relation's
 * scope drops them; a target kind that is not nested refuses them.
 *
 * @internal
 */
final readonly class RelationResolver
{
    public function __construct(
        private CompiledLayout $preset,
        private PlacementResolver $placement,
    ) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public function resolve(ResolvedArtifact $source, string $relationId, ?string $name = null, array $attributes = []): RelationResolution
    {
        $relation = $this->preset->relation($relationId);

        if ($relation->fromKind !== $source->kind->id) {
            return RelationResolution::unresolved(
                $relation,
                $source,
                "relation [{$relation->id}] starts from [{$relation->fromKind}], not [{$source->kind->id}]",
            );
        }

        $targetName = $relation->name->derive($source->name, $name);

        if ($targetName === null) {
            return RelationResolution::unresolved(
                $relation,
                $source,
                "relation [{$relation->id}] cannot derive the target name from [{$source->name}]; pass it explicitly",
            );
        }

        $nested = $relation->scope->applyNested($source->nested);

        try {
            $target = $this->placement->resolve(new ArtifactRequest(
                $relation->toKind,
                implode('/', [...$nested, $targetName]),
                $relation->scope->apply($source->context, $source->name),
                $attributes,
            ));
        } catch (ModException $exception) {
            return RelationResolution::unresolved($relation, $source, $exception->getMessage());
        }

        return RelationResolution::resolved($relation, $source, $target);
    }

    /**
     * Every relation that starts from the source's kind, resolved.
     *
     * @param  array<string, string|null>  $names  explicit target names by relation id
     * @return list<RelationResolution>
     */
    public function resolveAll(ResolvedArtifact $source, array $names = []): array
    {
        $resolutions = [];

        foreach ($this->preset->relationsFrom($source->kind->id) as $relation) {
            $resolutions[] = $this->resolve($source, $relation->id, $names[$relation->id] ?? null);
        }

        return $resolutions;
    }
}
