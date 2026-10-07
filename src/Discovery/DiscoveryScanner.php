<?php

namespace Tey\Mod\Discovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseMatch;
use Tey\Mod\Reverse\ReverseOutcome;

/**
 * A cold scan: walks the root of every enabled discovered kind, lets the
 * core reverse mapper decide ownership of each file and keeps only eligible
 * classes owned by a discovered kind.
 *
 * Symlinks are not followed. Files are visited in sorted order, so the
 * inventory is deterministic.
 */
final readonly class DiscoveryScanner
{
    private ReverseMapper $mapper;

    public function __construct(
        private Preset $preset,
        private string $basePath,
        private Eligibility $eligibility = new Eligibility,
    ) {
        $this->mapper = new ReverseMapper($preset);
    }

    /**
     * @param  list<DiscoveryDefinition>  $definitions
     */
    public function scan(array $definitions): Inventory
    {
        $entries = [];
        /** @var array<string, Rejection> $rejections */
        $rejections = [];
        /** @var array<string, ReverseMatch> $matches */
        $matches = [];

        foreach ($definitions as $definition) {
            if (! $definition->enabled) {
                continue;
            }

            $root = $this->preset->rule($definition->kindId)->root();

            foreach ($this->files($root->path) as $path) {
                $match = $matches[$path] ??= $this->mapper->fromPath($path);

                if ($match->outcome === ReverseOutcome::Matched) {
                    if ($match->artifact === null || $match->artifact->kind->id !== $definition->kindId) {
                        continue;
                    }

                    $result = $this->entry($definition, $match->artifact, $path);

                    if ($result instanceof Rejection) {
                        $rejections[$path] ??= $result;
                    } else {
                        $entries[] = $result;
                    }

                    continue;
                }

                if ($match->outcome === ReverseOutcome::Ambiguous && ! $this->concerns($match, $definition)) {
                    continue;
                }

                $rejections[$path] ??= $this->rejected($match, $path);
            }
        }

        ksort($rejections);

        return new Inventory($entries, array_values($rejections));
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
     * Relative, '/'-separated paths of the PHP files below a root directory, sorted.
     *
     * @return list<string>
     */
    private function files(string $rootPath): array
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
            if ($item->isLink() || ! $item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($item->getPathname(), strlen(rtrim($this->basePath, '/\\')) + 1);
            $files[] = str_replace('\\', '/', $relative);
        }

        sort($files);

        return $files;
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->basePath, '/\\').($relative === '' ? '' : DIRECTORY_SEPARATOR.$relative);
    }
}
