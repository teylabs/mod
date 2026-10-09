<?php

namespace Tey\Mod\Discovery;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\CompiledRoot;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Resolution\ModelRelations;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseMatch;
use Tey\Mod\Reverse\ReverseOutcome;
use Tey\Mod\Support\Path;

/**
 * A cold scan: walks the root of every enabled discovered kind, lets the
 * core reverse mapper decide ownership of each file and keeps only eligible
 * classes owned by a discovered kind.
 *
 * A kind declared `discover: 'anywhere'` also offers every file below its
 * dimension folders (minus its `except` folders) as a candidate; eligibility
 * still decides, and such candidates are never reported as rejections.
 * A `directory` definition collects the directories a file kind's template
 * binds that hold at least one file. A relation type (factory, policy) pairs
 * each model the layout owns with the related class that exists; a model
 * without one is not a rejection.
 *
 * Symlinks are not followed. Files are visited in sorted order, so the
 * inventory is deterministic. The host may replace the file walker with its
 * own candidate-file source; what it yields is still sorted and owned here.
 *
 * @internal used by Discovery::scan(); hosts replace the candidate-file source through DiscoveryOptions::withCandidates().
 */
final readonly class DiscoveryScanner
{
    private ReverseMapper $mapper;

    private ModelRelations $relations;

    /**
     * @param  (Closure(CompiledRoot, string, DiscoveryDefinition): iterable<string>)|null  $candidates
     */
    public function __construct(
        private CompiledLayout $preset,
        private string $basePath,
        private Eligibility $eligibility = new Eligibility,
        private ?Closure $candidates = null,
    ) {
        $this->mapper = new ReverseMapper($preset);
        $this->relations = new ModelRelations($preset);
    }

    /**
     * @param  list<DiscoveryDefinition>  $definitions
     */
    public function scan(array $definitions): Inventory
    {
        /** @var array<string, DiscoveredArtifact> $entries keyed by path + type */
        $entries = [];
        /** @var array<string, Rejection> $rejections */
        $rejections = [];
        /** @var array<string, ReverseMatch> $matches */
        $matches = [];
        /** @var array<string, list<string>> $files */
        $files = [];

        $enabled = array_values(array_filter($definitions, static fn (DiscoveryDefinition $definition): bool => $definition->enabled));
        $discoveredKinds = [];

        foreach ($enabled as $definition) {
            $discoveredKinds[$definition->kindId] = $definition->type;
        }

        foreach ($enabled as $definition) {
            $rule = $this->preset->rule($definition->kindId);
            $kind = $this->preset->kind($definition->kindId);

            if ($definition->type === DiscoveryType::Directory) {
                if ($rule instanceof TemplateRule) {
                    foreach ($this->directories($rule->root()->path) as $directory) {
                        foreach ($rule->variants() as $variant) {
                            $context = $variant->recogniseDirectory($directory);

                            if ($context !== null) {
                                $entries[$directory.'|directory'] = new DiscoveredArtifact($definition->kindId, DiscoveryType::Directory, '', $directory, $context->toArray());
                            }
                        }
                    }
                }

                continue;
            }

            // A host source may scope candidates per discovered kind, so the walk is cached per root and definition.
            $filesKey = $rule->root()->path.'|'.($this->candidates === null ? '' : $definition->identity());
            $files[$filesKey] ??= $this->files($rule->root(), $definition);

            if ($definition->type->isRelationType()) {
                foreach ($files[$filesKey] as $path) {
                    $match = $matches[$path] ??= $this->mapper->fromPath($path);

                    if ($match->outcome === ReverseOutcome::Matched && $match->artifact !== null && $match->artifact->kind->id === $definition->kindId) {
                        $this->pair($definition, $match->artifact, $path, $entries);
                    }
                }

                continue;
            }

            $anywhere = $rule instanceof TemplateRule && $rule->anywhere() ? $rule : null;

            foreach ($files[$filesKey] as $path) {
                $match = $matches[$path] ??= $this->mapper->fromPath($path);

                if ($match->outcome === ReverseOutcome::Matched && $match->artifact !== null) {
                    if ($match->artifact->kind->id === $definition->kindId) {
                        $this->collect($definition, $match->artifact, $path, $entries, $rejections, true);

                        continue;
                    }

                    // Owned by another kind. An anywhere kind still considers it unless that
                    // kind is itself discovered as the same type (it is reported there).
                    if ($anywhere === null || ($discoveredKinds[$match->artifact->kind->id] ?? null) === $definition->type) {
                        continue;
                    }
                }

                if ($anywhere !== null) {
                    $candidate = $anywhere->recogniseAnywhere($kind, $path);

                    if ($candidate !== null) {
                        $this->collect($definition, $candidate, $path, $entries, $rejections, false);
                    }

                    continue;
                }

                if ($match->outcome === ReverseOutcome::Matched) {
                    continue;
                }

                if ($match->outcome === ReverseOutcome::Ambiguous && ! $this->concerns($match, $definition)) {
                    continue;
                }

                $rejections[$path] ??= $this->rejected($match, $path);
            }
        }

        // A class that both listens and subscribes registers once, as a subscriber.
        foreach ($entries as $key => $entry) {
            if ($entry->type === DiscoveryType::Listener && isset($entries[$entry->path.'|'.DiscoveryType::Subscriber->value])) {
                unset($entries[$key]);
            }
        }

        foreach ($entries as $entry) {
            unset($rejections[$entry->path]);
        }

        ksort($rejections);

        return new Inventory(array_values($entries), array_values($rejections));
    }

    /**
     * @param  array<string, DiscoveredArtifact>  $entries
     * @param  array<string, Rejection>  $rejections
     */
    private function collect(DiscoveryDefinition $definition, ResolvedArtifact $artifact, string $path, array &$entries, array &$rejections, bool $owned): void
    {
        $result = $this->entry($definition, $artifact, $path);

        if ($result instanceof Rejection) {
            if ($owned) {
                $rejections[$path] ??= $result;
            }

            return;
        }

        $entries[$path.'|'.$definition->type->value] = $result;
    }

    /**
     * A model and its related class, when the model is an eligible Eloquent model and the class exists.
     *
     * @param  array<string, DiscoveredArtifact>  $entries
     */
    private function pair(DiscoveryDefinition $definition, ResolvedArtifact $model, string $path, array &$entries): void
    {
        $entry = $this->entry($definition, $model, $path);

        if (! $entry instanceof DiscoveredArtifact) {
            return;
        }

        $target = $this->relations->targetOf($model, $definition->type->value);

        if ($target !== null) {
            $entries[$path.'|'.$definition->type->value] = new DiscoveredArtifact($entry->kindId, $entry->type, $entry->class, $entry->path, $entry->context, [], $target);
        }
    }

    private function entry(DiscoveryDefinition $definition, ResolvedArtifact $artifact, string $path): DiscoveredArtifact|Rejection
    {
        $class = $artifact->fqcn();
        $absolute = $this->absolute($path);

        if ($class === null || ! class_exists($class)) {
            return new Rejection($path, RejectionReason::Ineligible, sprintf('the autoloader does not find class [%s]', $class ?? '?'), $definition->kindId, $class);
        }

        $result = $this->eligibility->check($definition->type, $class, $absolute);

        if (is_string($result)) {
            return new Rejection($path, RejectionReason::Ineligible, $result, $definition->kindId, $class);
        }

        return new DiscoveredArtifact($definition->kindId, $definition->type, $class, $path, $artifact->context->toArray(), $result);
    }

    private function concerns(ReverseMatch $match, DiscoveryDefinition $definition): bool
    {
        foreach ($match->candidates as $candidate) {
            if ($candidate->kind->id === $definition->kindId) {
                return true;
            }
        }

        return false;
    }

    private function rejected(ReverseMatch $match, string $path): Rejection
    {
        $reason = match ($match->outcome) {
            ReverseOutcome::Ambiguous => RejectionReason::Ambiguous,
            ReverseOutcome::Unsupported => RejectionReason::Unsupported,
            default => RejectionReason::NotOwned,
        };

        return new Rejection(
            $path,
            $reason,
            $match->reason ?? $reason->value,
            candidates: array_map(static fn (ResolvedArtifact $candidate): string => $candidate->describe(), $match->candidates),
        );
    }

    /**
     * Relative, '/'-separated paths of the PHP files below a root, sorted:
     * from the host's candidate-file source when it has one, else mod's walker.
     *
     * @return list<string>
     */
    private function files(CompiledRoot $root, DiscoveryDefinition $definition): array
    {
        if ($this->candidates !== null) {
            $prefix = CompiledRoot::normalisePath($root->path);
            $files = [];

            foreach (($this->candidates)($root, $this->basePath, $definition) as $path) {
                $path = CompiledRoot::normalisePath($path);

                if ($path !== '' && str_ends_with($path, '.php') && Path::relative($prefix, $path) !== null) {
                    $files[$path] = $path;
                }
            }

            $files = array_values($files);
            sort($files);

            return $files;
        }

        return $this->walk($root->path, static fn (SplFileInfo $item): bool => $item->isFile() && $item->getExtension() === 'php');
    }

    /**
     * Relative, '/'-separated paths of the directories below a root that hold at least one regular file, sorted.
     *
     * @return list<string>
     */
    private function directories(string $rootPath): array
    {
        $directories = [];

        foreach ($this->walk($rootPath, static fn (SplFileInfo $item): bool => $item->isFile()) as $file) {
            $directory = dirname($file);

            if ($directory !== '.' && $directory !== '') {
                $directories[$directory] = $directory;
            }
        }

        $directories = array_values($directories);
        sort($directories);

        return $directories;
    }

    /**
     * @param  Closure(SplFileInfo): bool  $accept
     * @return list<string>
     */
    private function walk(string $rootPath, Closure $accept): array
    {
        $directory = $this->absolute($rootPath);

        if (! is_dir($directory) || is_link($directory)) {
            return [];
        }

        $files = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isLink() || ! $accept($item)) {
                continue;
            }

            $relative = Path::relative($this->basePath, $item->getPathname());

            if ($relative !== null) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    private function absolute(string $relative): string
    {
        return Path::resolve($this->basePath, $relative);
    }
}
