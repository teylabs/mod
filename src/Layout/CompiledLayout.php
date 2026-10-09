<?php

namespace Tey\Mod\Layout;

use Illuminate\Support\Str;
use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\CompiledFileType;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\UnknownFileType;
use Tey\Mod\Exceptions\UnknownRelation;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Placement\Dimension;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Preset\PresetValidator;
use Tey\Mod\Relation\Relation;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Support\Path;
use Tey\Mod\Support\Stack;

/**
 * A coherent, validated set of roots, dimensions, kinds, placement rules and relations.
 *
 * Build one with CompiledLayout::fromArray() (the provisional internal definition
 * format, see PresetValidator) or the constructor. Immutable; hold as many
 * as you like side by side.
 *
 * @api
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
     * @param  array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}>  $templates
     * @param  array{pages: ?string, components: ?string, css: ?string, views: ?string, page_name: ?string}  $frontendPaths
     * @param  array<string, Stub>  $stubs  kind id → the stub the layout declares for it
     *
     * @internal
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
        private array $templates = [],
        private array $frontendPaths = ['pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null],
        private bool $mirrorPages = false,
    ) {}

    /**
     * @param  array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}>  $templates
     *
     * @internal
     */
    public function withTemplates(array $templates): self
    {
        $slots = array_merge([], ...array_column($templates, 'slots'));
        $dimensions = array_values(array_filter($this->dimensions, static fn (Dimension $dimension): bool => ! in_array($dimension->name, $slots, true)));

        return new self($this->roots, $dimensions, $this->kinds, $this->rules, $this->relations, $this->excludedRoots, $this->commandsEnabled, $this->placementOptions, $this->stubs, $templates, $this->frontendPaths, $this->mirrorPages);
    }

    /**
     * @return array<string, array{file: string, path: string, source: string, slots: list<string>, groups: list<string>, digest: string, relative: string, uses_base: bool, body_aliases?: array<string, string>}>
     *
     * @internal
     */
    public function templates(): array
    {
        return $this->templates;
    }

    /**
     *  the array definition is the layout compiler's output format and may change; define layouts with Mod::layout() and compile() them.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidLayout
     *
     * @internal
     */
    public static function fromArray(array $definition): self
    {
        return (new PresetValidator)->compile($definition);
    }

    /**
     * Resolved project-relative path templates and the declared page-name pattern.
     *
     * @return array{pages: ?string, components: ?string, css: ?string, views: ?string, page_name: ?string}
     *
     * @internal
     */
    public function frontend(?Stack $stack = null): array
    {
        $paths = $this->frontendPaths;
        if ($this->mirrorPages && $stack !== null && $paths['pages'] !== null) {
            $paths['pages'] = (string) preg_replace('~(^|/)pages(?=/|$)~', '$1'.basename($stack->pagesPath()), $paths['pages']);
        }

        return $paths;
    }

    /** resolve the active app's casing once when its layout compiles. @internal */
    public function withStack(Stack $stack): self
    {
        $paths = $this->frontend($stack);
        $roots = $this->roots;
        if ($this->frontendPaths['pages'] !== null && $paths['pages'] !== null) {
            foreach ($roots as $name => $root) {
                if (! $root->isClassRoot() && $root->path === dirname($this->frontendPaths['pages'])) {
                    $roots[$name] = CompiledRoot::files(dirname($paths['pages']));
                }
            }
        }

        $kinds = $this->kinds;
        $rules = $this->rules;
        if (isset($kinds['page'])) {
            $kind = $kinds['page'];
            $extension = $stack->inertia() === 'react' ? ($stack->typescript() ? '.tsx' : '.jsx') : '.vue';
            $kinds['page'] = new ArtifactKind($kind->id, $kind->shape, $kind->namePolicy, $kind->command, $kind->aliases, $kind->label, $extension, $kind->case);
            if ($this->mirrorPages && $rules['page'] instanceof TemplateRule) {
                $rules['page'] = $rules['page']->withPageFolder(basename($stack->pagesPath()));
            }
        }

        return new self($roots, $this->dimensions, $kinds, $rules, $this->relations, $this->excludedRoots, $this->commandsEnabled, $this->placementOptions, $this->stubs, $this->templates, $paths, $this->mirrorPages);
    }

    /** Whether a candidate lies inside a declared root that holds no classes. @internal */
    public function isPlainFilePath(string $path): bool
    {
        $path = Path::normalize($path);
        foreach ($this->roots as $root) {
            if ($root->isClassRoot()) {
                continue;
            }
            $pattern = '';
            foreach (explode('/', $root->path) as $index => $part) {
                $segment = Segment::parse($part);
                $multi = $segment->multi;
                foreach ($this->dimensions as $dimension) {
                    if ($dimension->name === $segment->dimension) {
                        $multi = $multi || $dimension->multi;
                    }
                }
                $fragment = ($index === 0 ? '' : '/').($segment->isDimension() ? ($multi ? '.+?' : '[^/]+') : preg_quote($part, '~'));
                $pattern .= $segment->required ? $fragment : '(?:'.$fragment.')?';
            }
            if (preg_match('~^'.$pattern.'(?:/|$)~', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The mounted namespace for a project path, or its folder-derived default. @api */
    public function namespaceFor(string $path): string
    {
        $path = Path::normalize($path);
        $best = null;
        foreach ($this->roots as $root) {
            if ($root->namespace !== null && Path::relative($root->path, $path) !== null
                && ($best === null || strlen($root->path) > strlen($best->path))) {
                $best = $root;
            }
        }
        if ($best !== null) {
            $below = (string) Path::relative($best->path, $path);

            return $best->namespace.($below === '' ? '' : str_replace('/', '\\', $below).'\\');
        }

        return Str::studly(basename($path)).'\\';
    }

    /**
     * @return array<string, CompiledRoot>
     *
     * @api
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     *  use dimensionNames() and placementOptions(); Dimension is internal
     *
     * @return list<Dimension>
     *
     * @internal
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * @return list<string>
     *
     * @api
     */
    public function dimensionNames(): array
    {
        return array_map(static fn (Dimension $dimension): string => $dimension->name, $this->dimensions);
    }

    /**
     * @return array<string, CompiledFileType>
     *
     * @api
     */
    public function fileTypes(): array
    {
        return array_map(static fn (ArtifactKind $type): CompiledFileType => new CompiledFileType($type), $this->kinds);
    }

    /** @api */
    public function fileType(string $fileType): CompiledFileType
    {
        return new CompiledFileType($this->kind($fileType));
    }

    /** @api */
    public function hasFileType(string $fileType): bool
    {
        return $this->hasKind($fileType);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     *
     * @api
     */
    public function place(string $fileType, string $name, PlacementContext $context, array $attributes = []): ResolvedArtifact
    {
        return (new PlacementResolver($this))->resolve(ArtifactRequest::for($fileType, $name, $context, $attributes));
    }

    /** @api */
    public function locate(string $fqcn): ?ResolvedArtifact
    {
        $match = (new ReverseMapper($this))->fromClass($fqcn);

        return $match->isMatched() ? $match->artifact : null;
    }

    /**
     * @internal
     *
     * @return array<string, ArtifactKind>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** @internal */
    public function hasKind(string $kindId): bool
    {
        return isset($this->kinds[$kindId]);
    }

    /** @internal */
    public function kind(string $kindId): ArtifactKind
    {
        return $this->kinds[$kindId] ?? throw UnknownFileType::id($kindId);
    }

    /**
     * @return array<string, PlacementRule>
     *
     * @internal
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @internal */
    public function rule(string $kindId): PlacementRule
    {
        return $this->rules[$kindId] ?? throw UnknownFileType::id($kindId);
    }

    /**
     * @return array<string, Relation>
     *
     * @api
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /** @api */
    public function relation(string $relationId): Relation
    {
        return $this->relations[$relationId] ?? throw UnknownRelation::id($relationId);
    }

    /**
     * @return list<Relation>
     *
     * @api
     */
    public function relationsFrom(string $fileType): array
    {
        return array_values(array_filter(
            $this->relations,
            static fn (Relation $relation): bool => $relation->fromKind === $fileType,
        ));
    }

    /**
     * @return list<CompiledRoot>
     *
     * @internal
     */
    public function excludedRoots(): array
    {
        return $this->excludedRoots;
    }

    /**
     * The command option of each dimension (`--module=`): the dimension in
     * kebab-case unless the layout renamed it with ->path().
     *
     * @return array<string, string> dimension name → option name
     *
     * @api
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
     *
     * @internal
     */
    public function stub(string $kindId): ?Stub
    {
        return $this->stubs[$kindId] ?? null;
    }

    /**
     * Whether the host wants the built-in mod:* commands registered (config key `mod.commands`).
     *
     * @internal
     */
    public function commandsEnabled(): bool
    {
        return $this->commandsEnabled;
    }
}
