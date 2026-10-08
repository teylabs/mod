<?php

namespace Tey\Mod\Resolution;

use Illuminate\Database\Eloquent\Model;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationPolicy;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Reverse\ReverseMapper;

/**
 * The class a model's relation names: the factory or policy the layout
 * places for that model, when that class exists. Laravel's own model kind is
 * `model`; a relation from it to the target kind (`factory`, `policy`) decides
 * where the related class lives, whatever the layout.
 *
 * @internal used by discovery and ModelConventions.
 */
final readonly class ModelRelations
{
    public const MODEL_KIND = 'model';

    private RelationResolver $relations;

    private ReverseMapper $mapper;

    public function __construct(private Preset $preset)
    {
        $this->relations = new RelationResolver($preset, new PlacementResolver($preset));
        $this->mapper = new ReverseMapper($preset);
    }

    /**
     * Whether the layout declares a relation from its model kind to the target kind.
     */
    public function declares(string $targetKind): bool
    {
        return $this->preset->hasKind(self::MODEL_KIND)
            && $this->preset->kind(self::MODEL_KIND)->isClass()
            && $this->relationIds($targetKind) !== [];
    }

    /**
     * The existing class the model's relation to the target kind resolves to.
     */
    public function targetOf(ResolvedArtifact $model, string $targetKind): ?string
    {
        if ($model->kind->id !== self::MODEL_KIND) {
            return null;
        }

        foreach ($this->relationIds($targetKind) as $relationId) {
            $resolution = $this->relations->resolve($model, $relationId);
            $class = $resolution->target?->fqcn();

            if ($class !== null && class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The same, starting from a model class name: null unless the layout owns
     * the class as its model kind and the related class exists.
     */
    public function targetOfClass(string $model, string $targetKind): ?string
    {
        if (! is_subclass_of($model, Model::class)) {
            return null;
        }

        $match = $this->mapper->fromClass($model);

        return $match->isMatched() && $match->artifact !== null && $match->artifact->fqcn() === ltrim($model, '\\')
            ? $this->targetOf($match->artifact, $targetKind)
            : null;
    }

    /**
     * @return list<string>
     */
    private function relationIds(string $targetKind): array
    {
        $ids = [];

        foreach ($this->preset->relationsFrom(self::MODEL_KIND) as $relation) {
            if ($relation->toKind === $targetKind && $relation->policy !== RelationPolicy::None && $this->preset->hasKind($targetKind) && $this->preset->kind($targetKind)->isClass()) {
                $ids[] = $relation->id;
            }
        }

        return $ids;
    }
}
