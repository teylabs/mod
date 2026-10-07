<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\DimensionNotApplicable;
use Tey\Mod\Exceptions\InvalidArtifactName;
use Tey\Mod\Exceptions\MissingDimension;

/**
 * A declarative placement: root + ordered segments + the kind's name policy.
 *
 * Because every part is data, the rule is an exact inverse of itself.
 */
final readonly class TemplateRule implements PlacementRule
{
    /**
     * @param  list<Segment>  $segments
     */
    public function __construct(
        private string $kindId,
        private Root $root,
        private array $segments,
        private int $priority = 0,
    ) {}

    public function kindId(): string
    {
        return $this->kindId;
    }

    public function root(): Root
    {
        return $this->root;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * @return list<Segment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * @return list<string>
     */
    public function dimensions(): array
    {
        $names = [];

        foreach ($this->segments as $segment) {
            if ($segment->dimension !== null) {
                $names[] = $segment->dimension;
            }
        }

        return $names;
    }

    /**
     * A fingerprint that is equal for two rules that would place identically.
     */
    public function pattern(): string
    {
        return ($this->root->namespace ?? '').'|'.$this->root->path.'|'.implode('/', array_map(
            static fn (Segment $segment): string => $segment->describe(),
            $this->segments,
        ));
    }

    public function place(ArtifactKind $kind, string $name, PlacementContext $context, array $attributes): ResolvedArtifact
    {
        if (Identifier::isNested($name)) {
            // Checked first: "mod:model Billing/Invoice" needs the --in hint more than a missing-dimension error.
            throw InvalidArtifactName::nested($name, $this->dimensions());
        }

        $parts = [];
        $used = [];

        foreach ($this->segments as $segment) {
            if ($segment->literal !== null) {
                $parts[] = $segment->literal;

                continue;
            }

            $dimension = (string) $segment->dimension;
            $value = $context->get($dimension);
            $used[] = $dimension;

            if ($value === null) {
                if ($segment->required) {
                    throw MissingDimension::for($kind->id, $dimension);
                }

                continue;
            }

            $parts[] = $value;
        }

        foreach ($context->names() as $given) {
            if (! in_array($given, $used, true)) {
                throw DimensionNotApplicable::for($kind->id, $given);
            }
        }

        $basename = $kind->namePolicy->basename($kind->id, $kind->shape, $name, $attributes);
        $stem = $kind->namePolicy->stem($kind->shape, $basename) ?? $name;

        return new ResolvedArtifact($kind, $context->only($used), $stem, $this->identity($kind, $parts, $basename));
    }

    public function isInvertible(): bool
    {
        return true;
    }

    public function recognise(ArtifactKind $kind, string $subject, bool $isPath): array
    {
        if ($isPath) {
            $remainder = $this->root->pathRemainder($subject);

            if ($remainder === null || ! str_ends_with($remainder, '.php')) {
                return [];
            }

            $parts = explode('/', substr($remainder, 0, -4));
        } else {
            if (! $kind->isClass()) {
                return [];
            }

            $remainder = $this->root->namespaceRemainder($subject);

            if ($remainder === null) {
                return [];
            }

            $parts = explode('\\', $remainder);
        }

        if (in_array('', $parts, true)) {
            return [];
        }

        $basename = array_pop($parts);
        $stem = $kind->namePolicy->stem($kind->shape, $basename);

        if ($stem === null) {
            return [];
        }

        $matches = [];

        foreach ($this->bind(0, $parts, 0, PlacementContext::none()) as $context) {
            $matches[] = new ResolvedArtifact($kind, $context, $stem, $this->identity($kind, $parts, $basename));
        }

        return $matches;
    }

    /**
     * Bind template segments to concrete parts, exploring every way optional dimensions can be absent.
     *
     * @param  list<string>  $parts
     * @return list<PlacementContext>
     */
    private function bind(int $segmentIndex, array $parts, int $partIndex, PlacementContext $bound): array
    {
        if ($segmentIndex === count($this->segments)) {
            return $partIndex === count($parts) ? [$bound] : [];
        }

        $segment = $this->segments[$segmentIndex];
        $part = $parts[$partIndex] ?? null;

        if ($segment->literal !== null) {
            return $part === $segment->literal
                ? $this->bind($segmentIndex + 1, $parts, $partIndex + 1, $bound)
                : [];
        }

        $dimension = (string) $segment->dimension;
        $results = [];

        if ($part !== null && Identifier::isClassSegment($part)) {
            $results = $this->bind($segmentIndex + 1, $parts, $partIndex + 1, $bound->with($dimension, $part));
        }

        if (! $segment->required) {
            $results = [...$results, ...$this->bind($segmentIndex + 1, $parts, $partIndex, $bound)];
        }

        return $results;
    }

    /**
     * @param  list<string>  $parts
     */
    private function identity(ArtifactKind $kind, array $parts, string $basename): ClassIdentity|FileIdentity
    {
        $path = $this->root->pathFor($parts, $basename.'.php');

        if ($kind->shape === IdentityShape::File || ! $this->root->isClassRoot()) {
            return new FileIdentity($path);
        }

        return new ClassIdentity($this->root->namespaceFor($parts), $basename, $path);
    }
}
