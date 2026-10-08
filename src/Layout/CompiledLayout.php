<?php

namespace Tey\Mod\Layout;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\UnknownKind;
use Tey\Mod\Exceptions\UnknownRelation;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Placement\Dimension;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Preset\PresetValidator;
use Tey\Mod\Relation\Relation;

/**
 * A coherent, validated set of roots, dimensions, kinds, placement rules and relations.
 *
 * Build one with CompiledLayout::fromArray() (the provisional internal definition
 * format, see PresetValidator) or the constructor. Immutable; hold as many
 * as you like side by side.
 */
final readonly class CompiledLayout
{
    /**
     * @param  array<string, CompiledRoot>  $roots  keyed by root name
     * @param  list<Dimension>  $dimensions  in declared order (the order --in values are read)
     * @param  array<string, ArtifactKind>  $kinds  keyed by kind id
     * @param  array<string, PlacementRule>  $rules  keyed by kind id
     * @param  array<string, Relation>  $relations  keyed by relation id
     * @param  list<CompiledRoot>  $excludedRoots  never owned by any rule
     * @param  array<string, string>  $placementOptions  dimension name → command option name
     * @param  array<string, Stub>  $stubs  kind id → the stub the layout declares for it
     */
    public function __construct(
        private array $roots,
        private array $dimensions,
        private array $kinds,
        private array $rules,
        private array $relations = [],
        private array $excludedRoots = [],
        private bool $commandsEnabled = true,
        private array $placementOptions = [],
        private array $stubs = [],
    ) {}

    /**
     * @internal the array definition is the layout compiler's output format and may change; define layouts with Mod::layout() and compile() them.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidLayout
     */
    public static function fromArray(array $definition): self
    {
        return (new PresetValidator)->compile($definition);
    }

    /**
     * @return array<string, CompiledRoot>
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
        return $this->kinds[$kindId] ?? throw UnknownKind::id($kindId);
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
        return $this->rules[$kindId] ?? throw UnknownKind::id($kindId);
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
     * @return list<CompiledRoot>
     */
    public function excludedRoots(): array
    {
        return $this->excludedRoots;
    }

    /**
     * The command option of each dimension (`--module=`): the dimension in
     * kebab-case unless the layout renamed it with ->placementOption().
     *
     * @return array<string, string> dimension name → option name
     */
    public function placementOptions(): array
    {
        $options = [];

        foreach ($this->dimensions as $dimension) {
            $options[$dimension->name] = $this->placementOptions[$dimension->name]
                ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $dimension->name));
        }

        return $options;
    }

    /**
     * The stub the layout declares for a kind, if any.
     */
    public function stub(string $kindId): ?Stub
    {
        return $this->stubs[$kindId] ?? null;
    }

    /**
     * Whether the host wants the built-in mod:* commands registered (config key `mod.commands`).
     */
    public function commandsEnabled(): bool
    {
        return $this->commandsEnabled;
    }
}
