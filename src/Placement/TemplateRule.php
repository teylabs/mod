<?php

namespace Tey\Mod\Placement;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ClassIdentity;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\Identifier;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\DimensionNotApplicable;
use Tey\Mod\Exceptions\InvalidName;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Generation\PlainFile\Casing;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;

/**
 * A declarative placement: root + ordered segments + the kind's name policy.
 *
 * Because every part is data, the rule is an exact inverse of itself.
 *
 * A nested rule (`nested: true`) also accepts nested names: "Billing/Invoice"
 * places the folders after the template and the basename last, exactly like
 * native make:* does, and recognizes them back as the artifact's `nested`
 * folders. A multi-segment dimension (`{name+}`) spans one or more folders.
 *
 * An "anywhere" rule (`discover: 'anywhere'`) additionally offers discovery
 * every file below its bound dimension folders, minus the `except` folders;
 * that widening is for discovery only and never reaches reverse mapping.
 *
 * @internal placement machinery behind CompiledLayout::rule(); build layouts with Mod::layout().
 */
final readonly class TemplateRule implements PlacementRule
{
    /**
     * @param  list<Segment>  $segments
     * @param  list<string>  $except  folders (relative to the dimension folder, '/'-joined) discovery skips for an anywhere rule
     */
    public function __construct(
        private string $kindId,
        private CompiledRoot $root,
        private array $segments,
        private int $priority = 0,
        private bool $nested = false,
        private bool $anywhere = false,
        private array $except = [],
        private ?self $fallback = null,
    ) {}

    /** @return list<self> */
    public function variants(): array
    {
        return $this->fallback === null ? [$this] : [
            new self($this->kindId, $this->root, $this->segments, $this->priority, $this->nested, $this->anywhere, $this->except),
            $this->fallback,
        ];
    }

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

    /** @internal Scaffold recursion uses the ordinary name-subfolder grammar. */
    public function withNestedNames(): self
    {
        return new self($this->kindId, $this->root, $this->segments, $this->priority, true, $this->anywhere, $this->except, $this->fallback?->withNestedNames());
    }

    /** @internal Mirror the starter kit's pages folder without changing dimension slots. */
    public function withPageFolder(string $folder): self
    {
        $segments = array_map(static fn (Segment $segment): Segment => $segment->literal === 'pages' ? Segment::literal($folder) : $segment, $this->segments);

        return new self($this->kindId, $this->root, $segments, $this->priority, $this->nested, $this->anywhere, $this->except, $this->fallback?->withPageFolder($folder));
    }

    /** @internal A custom member keeps its file-type suffix after the last group.
     * @param  list<string>  $groups
     */
    public function withoutGroup(array $groups): self
    {
        $last = -1;
        foreach ($this->segments as $index => $segment) {
            if ($segment->dimension !== null && in_array($segment->dimension, $groups, true)) {
                $last = $index;
            }
        }

        return new self($this->kindId, $this->root, array_values(array_filter($this->segments, static fn (int $index): bool => $index > $last, ARRAY_FILTER_USE_KEY)), $this->priority, true);
    }

    public function nested(): bool
    {
        return $this->nested;
    }

    public function anywhere(): bool
    {
        return $this->anywhere;
    }

    /**
     * @return list<string>
     */
    public function except(): array
    {
        return $this->except;
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
        )).($this->nested ? '|nested' : '').($this->anywhere ? '|anywhere:'.implode(',', $this->except) : '');
    }

    public function place(ArtifactKind $kind, string $name, PlacementContext $context, array $attributes): ResolvedArtifact
    {
        if ($context->isEmpty() && $this->fallback !== null) {
            foreach ($this->segments as $segment) {
                if ($segment->dimension !== null && $segment->required) {
                    return $this->fallback->place($kind, $name, $context, $attributes);
                }
            }
        }

        $nested = [];
        if ($kind->extension !== null && (str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('~(^|[/\\\\])\.\.?([/\\\\]|$)~', $name) === 1)) {
            throw InvalidName::malformed($name, 'a relative plain file name without traversal');
        }

        if (Identifier::isNested($name)) {
            if (! $this->nested) {
                // Checked first: "mod:model Billing/Invoice" needs the --in hint more than a missing-dimension error.
                throw InvalidName::nested($name, $this->dimensions());
            }

            [$nested, $name] = Identifier::splitNested($name);

            foreach ($nested as $folder) {
                if (! ($kind->extension === null ? Identifier::isClassSegment($folder) : preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $folder) === 1) || $folder === '.' || $folder === '..') {
                    throw InvalidName::malformed($folder, 'a folder name inside a nested name');
                }
            }
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

            if ($segment->multi) {
                if (! Identifier::isSegmentChain($value)) {
                    throw InvalidName::malformed($value, 'a "/"-separated chain of folder names for ['.$dimension.']');
                }

                array_push($parts, ...explode('/', $value));

                continue;
            }

            $parts[] = $value;
        }

        foreach ($context->names() as $given) {
            if (! in_array($given, $used, true)) {
                throw DimensionNotApplicable::for($kind->id, $given);
            }
        }

        if ($kind->extension !== null) {
            $case = $kind->case ?? Casing::forExtension($kind->extension);
            $nested = array_map(static fn (string $folder): string => Casing::folder($folder, $case), $nested);
            $name = Casing::name($name, $case);
        }

        if ($kind->extension !== null && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw InvalidName::malformed($name, 'a plain file name');
        }
        $basename = $kind->extension !== null ? $name : $kind->namePolicy->basename($kind->id, $kind->shape, $name, $attributes);
        $stem = $kind->extension !== null ? $name : ($kind->namePolicy->stem($kind->shape, $basename) ?? $name);

        return new ResolvedArtifact($kind, $context->only($used), $stem, $this->identity($kind, [...$parts, ...$nested], $basename), $nested);
    }

    public function isInvertible(): bool
    {
        return true;
    }

    public function recognise(ArtifactKind $kind, string $subject, bool $isPath): array
    {
        if ($this->fallback !== null) {
            $matches = [];

            foreach ($this->variants() as $rule) {
                array_push($matches, ...$rule->recognise($kind, $subject, $isPath));
            }

            return $matches;
        }

        $parts = $this->parts($kind, $subject, $isPath);

        if ($parts === null) {
            return [];
        }

        $basename = array_pop($parts);
        $stem = $basename === null ? null : ($kind->extension !== null ? $basename : $kind->namePolicy->stem($kind->shape, $basename));

        if ($basename === null || $stem === null) {
            return [];
        }

        $bindings = [];

        foreach ($this->bind(0, $parts, 0, PlacementContext::none()) as [$context, $consumed]) {
            if ($consumed < count($parts) && ! $this->nested) {
                continue;
            }

            $bindings[] = [$context, $consumed];
        }

        // A multi-segment dimension followed by nested folders could split anywhere;
        // the dimension binds minimally and the folders take the rest (as discovery does).
        if ($this->nested && $this->multi() && $bindings !== []) {
            $shortest = min(array_column($bindings, 1));
            $bindings = array_values(array_filter($bindings, static fn (array $binding): bool => $binding[1] === $shortest));
        }

        $matches = [];

        foreach ($bindings as [$context, $consumed]) {
            $matches[] = new ResolvedArtifact($kind, $context, $stem, $this->identity($kind, $parts, $basename), self::after($parts, $consumed));
        }

        return $matches;
    }

    /**
     * Discovery widening for an anywhere rule: the artifact a file below this
     * rule's dimension folders would be, whatever folder it sits in.
     *
     * The leading dimension segments bind minimally (one folder for `{x+}`),
     * literal template folders are not required, every remaining folder is
     * recorded as `nested`, and files inside an `except` folder are skipped.
     * Returns null when the file is not a candidate.
     */
    public function recogniseAnywhere(ArtifactKind $kind, string $path): ?ResolvedArtifact
    {
        if (! $this->anywhere) {
            return null;
        }

        $parts = $this->parts($kind, $path, true);

        if ($parts === null) {
            return null;
        }

        $basename = array_pop($parts);
        $stem = $basename === null ? null : ($kind->extension !== null ? $basename : $kind->namePolicy->stem($kind->shape, $basename));

        if ($basename === null || $stem === null) {
            return null;
        }

        $context = PlacementContext::none();
        $index = 0;
        // The dimension folders are the template's leading fixed folders plus
        // the dimension slots after them ("Modules/{module}"); a template that
        // starts with no dimension slot offers its whole root.
        $leadsToDimension = $this->leadsToDimension();
        $seenDimension = false;

        foreach ($this->segments as $segment) {
            if ($segment->literal !== null) {
                if (! $leadsToDimension || $seenDimension) {
                    break;
                }

                if (($parts[$index] ?? null) !== $segment->literal) {
                    return null;
                }

                $index++;

                continue;
            }

            $seenDimension = true;
            $part = $parts[$index] ?? null;

            if ($part === null || ! Identifier::isClassSegment($part)) {
                if ($segment->required) {
                    return null;
                }

                continue;
            }

            $context = $context->with((string) $segment->dimension, $part);
            $index++;
        }

        $nested = self::after($parts, $index);
        $below = implode('/', $nested);

        foreach ($this->except as $folder) {
            if ($below === $folder || str_starts_with($below, $folder.'/')) {
                return null;
            }
        }

        return new ResolvedArtifact($kind, $context, $stem, $this->identity($kind, $parts, $basename), $nested);
    }

    /**
     * Discovery of directories for a file kind: the context a directory binds
     * when its path is exactly this rule's template (every segment consumed).
     */
    public function recogniseDirectory(string $path): ?PlacementContext
    {
        $remainder = $this->root->pathRemainder(CompiledRoot::normalisePath($path));

        if ($remainder === null || $remainder === '') {
            return null;
        }

        $parts = explode('/', $remainder);

        if (in_array('', $parts, true)) {
            return null;
        }

        foreach ($this->bind(0, $parts, 0, PlacementContext::none()) as [$context, $consumed]) {
            if ($consumed === count($parts)) {
                return $context;
            }
        }

        return null;
    }

    /**
     * The subject split into folder parts plus basename, or null when it is not under this rule's root.
     *
     * @return list<string>|null
     */
    private function parts(ArtifactKind $kind, string $subject, bool $isPath): ?array
    {
        if ($isPath) {
            $remainder = $this->root->pathRemainder($subject);

            $extension = $kind->extension ?? '.php';
            if ($remainder === null || ! str_ends_with($remainder, $extension)) {
                return null;
            }

            $parts = explode('/', substr($remainder, 0, -strlen($extension)));
        } else {
            if (! $kind->isClass()) {
                return null;
            }

            $remainder = $this->root->namespaceRemainder($subject);

            if ($remainder === null) {
                return null;
            }

            $parts = explode('\\', $remainder);
        }

        return in_array('', $parts, true) ? null : $parts;
    }

    /**
     * Bind template segments to concrete parts, exploring every way optional
     * and multi-segment dimensions can be absent or span. Each binding is the
     * context plus how many parts it consumed; the caller decides whether the
     * remainder is acceptable (nested folders) or not.
     *
     * @param  list<string>  $parts
     * @return list<array{0: PlacementContext, 1: int}>
     */
    private function bind(int $segmentIndex, array $parts, int $partIndex, PlacementContext $bound): array
    {
        if ($segmentIndex === count($this->segments)) {
            return [[$bound, $partIndex]];
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

        if ($segment->multi) {
            $chain = [];

            for ($end = $partIndex; $end < count($parts); $end++) {
                if (! Identifier::isClassSegment($parts[$end])) {
                    break;
                }

                $chain[] = $parts[$end];
                $results = [...$results, ...$this->bind($segmentIndex + 1, $parts, $end + 1, $bound->with($dimension, implode('/', $chain)))];
            }
        } elseif ($part !== null && Identifier::isClassSegment($part)) {
            $results = $this->bind($segmentIndex + 1, $parts, $partIndex + 1, $bound->with($dimension, $part));
        }

        if (! $segment->required) {
            $results = [...$results, ...$this->bind($segmentIndex + 1, $parts, $partIndex, $bound)];
        }

        return $results;
    }

    /**
     * Whether a dimension slot follows the template's leading fixed folders.
     */
    private function leadsToDimension(): bool
    {
        foreach ($this->segments as $segment) {
            if ($segment->isDimension()) {
                return true;
            }
        }

        return false;
    }

    private function multi(): bool
    {
        foreach ($this->segments as $segment) {
            if ($segment->multi) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parts from the given index on.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function after(array $parts, int $from): array
    {
        $rest = [];

        for ($i = $from; $i < count($parts); $i++) {
            $rest[] = $parts[$i];
        }

        return $rest;
    }

    /**
     * @param  list<string>  $parts
     */
    private function identity(ArtifactKind $kind, array $parts, string $basename): ClassIdentity|FileIdentity
    {
        $path = $this->root->pathFor($parts, $basename.($kind->extension ?? '.php'));

        if ($kind->shape === IdentityShape::File || ! $this->root->isClassRoot()) {
            return new FileIdentity($path);
        }

        return new ClassIdentity($this->root->namespaceFor($parts), $basename, $path);
    }
}
