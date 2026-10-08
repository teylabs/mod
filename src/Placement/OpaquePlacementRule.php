<?php

namespace Tey\Mod\Placement;

use Closure;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\InvalidName;
use Tey\Mod\Layout\CompiledRoot;

/**
 * A callback-driven placement. It can place but never recognise: arbitrary
 * callbacks are not invertible, so reverse mapping reports Unsupported for
 * anything under its root instead of guessing.
 *
 * A nested rule accepts nested names and places their folders after the
 * callback's sub-namespace.
 *
 * @internal placement machinery behind Kind::place(); build layouts with Mod::layout().
 */
final readonly class OpaquePlacementRule implements PlacementRule
{
    /**
     * @param  Closure(string, PlacementContext): string  $subNamespace  returns the namespace (or folder path) below the root, "" for none
     * @param  list<string>  $dimensions
     */
    public function __construct(
        private string $kindId,
        private CompiledRoot $root,
        private Closure $subNamespace,
        private array $dimensions = [],
        private int $priority = 0,
        private bool $nested = false,
    ) {}

    public function kindId(): string
    {
        return $this->kindId;
    }

    public function root(): CompiledRoot
    {
        return $this->root;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function nested(): bool
    {
        return $this->nested;
    }

    /**
     * @return list<string>
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function place(ArtifactKind $kind, string $name, PlacementContext $context, array $attributes): ResolvedArtifact
    {
        $nested = [];

        if (Identifier::isNested($name)) {
            if (! $this->nested) {
                throw InvalidName::nested($name, $this->dimensions);
            }

            [$nested, $name] = Identifier::splitNested($name);

            foreach ($nested as $folder) {
                if (! Identifier::isClassSegment($folder)) {
                    throw InvalidName::malformed($folder, 'a folder name inside a nested name');
                }
            }
        }

        $basename = $kind->namePolicy->basename($kind->id, $kind->shape, $name, $attributes);
        $below = trim(str_replace('/', '\\', ($this->subNamespace)($name, $context)), '\\');
        $parts = [...($below === '' ? [] : explode('\\', $below)), ...$nested];
        $path = $this->root->pathFor($parts, $basename.'.php');

        $identity = $kind->shape === IdentityShape::File || ! $this->root->isClassRoot()
            ? new FileIdentity($path)
            : new ClassIdentity($this->root->namespaceFor($parts), $basename, $path);

        return new ResolvedArtifact($kind, $context, $name, $identity, $nested);
    }

    public function isInvertible(): bool
    {
        return false;
    }

    public function recognise(ArtifactKind $kind, string $subject, bool $isPath): array
    {
        return [];
    }

    /**
     * Whether the subject lies under this rule's root, where the rule might have placed it.
     */
    public function covers(string $subject, bool $isPath): bool
    {
        return $isPath
            ? $this->root->pathRemainder($subject) !== null
            : $this->root->namespaceRemainder($subject) !== null;
    }
}
