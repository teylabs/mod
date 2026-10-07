<?php

namespace Tey\Mod\Preset;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Exceptions\UnknownArtifactKind;
use Tey\Mod\Exceptions\UnknownRelation;
use Tey\Mod\Placement\Dimension;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\Root;
use Tey\Mod\Relation\Relation;

/**
 * A coherent, validated set of roots, dimensions, kinds, placement rules and relations.
 *
 * Build one with Preset::fromArray() (the provisional internal definition
 * format, see PresetValidator) or the constructor. Immutable; hold as many
 * as you like side by side.
 */
final readonly class Preset
{
    /**
     * @param  array<string, Root>  $roots  keyed by root name
     * @param  list<Dimension>  $dimensions  in declared order (the order --in values are read)
     * @param  array<string, ArtifactKind>  $kinds  keyed by kind id
     * @param  array<string, PlacementRule>  $rules  keyed by kind id
     * @param  array<string, Relation>  $relations  keyed by relation id
     * @param  list<Root>  $excludedRoots  never owned by any rule
     */
    public function __construct(
        private array $roots,
        private array $dimensions,
        private array $kinds,
        private array $rules,
        private array $relations = [],
        private array $excludedRoots = [],
        private bool $commandsEnabled = true,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidPreset
     */
    public static function fromArray(array $definition): self
    {
        return (new PresetValidator)->compile($definition);
    }

    /**
     * @return array<string, Root>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * @return list<Dimension>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * @return list<string>
     */
    public function dimensionNames(): array
    {
        return array_map(static fn (Dimension $dimension): string => $dimension->name, $this->dimensions);
    }

    /**
     * @return array<string, ArtifactKind>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function hasKind(string $kindId): bool
    {
        return isset($this->kinds[$kindId]);
    }

    public function kind(string $kindId): ArtifactKind
    {
        return $this->kinds[$kindId] ?? throw UnknownArtifactKind::id($kindId);
    }

    /**
     * @return array<string, PlacementRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function rule(string $kindId): PlacementRule
    {
        return $this->rules[$kindId] ?? throw UnknownArtifactKind::id($kindId);
    }

    /**
     * @return array<string, Relation>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    public function relation(string $relationId): Relation
    {
        return $this->relations[$relationId] ?? throw UnknownRelation::id($relationId);
    }

    /**
     * @return list<Relation>
     */
    public function relationsFrom(string $kindId): array
    {
        return array_values(array_filter(
            $this->relations,
            static fn (Relation $relation): bool => $relation->fromKind === $kindId,
        ));
    }

    /**
     * @return list<Root>
     */
    public function excludedRoots(): array
    {
        return $this->excludedRoots;
    }

    /**
     * Whether the host wants the built-in mod:* commands registered (config key `mod.commands`).
     */
    public function commandsEnabled(): bool
    {
        return $this->commandsEnabled;
    }
}
