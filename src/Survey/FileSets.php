<?php

namespace Tey\Mod\Survey;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Resolution\ModelRelations;

/**
 * @internal Connected app files and co-located files are independent evidence, not ownership claims.
 *
 * @phpstan-import-type Edge from PhpStructure
 *
 * @phpstan-type Set array{basis: string, files: list<string>, types: list<string>, anchor?: string}
 */
final class FileSets
{
    /**
     * @param  array<string, string>  $nodes
     * @param  list<Edge>  $edges
     * @param  array<string, ResolvedArtifact>  $artifacts
     * @return array{sets: list<Set>, recurring_sets: list<array{basis: string, types: list<string>, occurrences: list<list<string>>}>}
     */
    public function read(array $nodes, array $edges, array $artifacts): array
    {
        $adjacent = [];
        $outgoing = [];
        foreach ($edges as $edge) {
            $from = $nodes[$edge['from']] ?? null;
            $to = $nodes[$edge['to']] ?? null;
            if ($from !== null && $to !== null && $from !== $to) {
                $adjacent[$from][] = $to;
                $adjacent[$to][] = $from;
                $outgoing[$from][] = $to;
            }
        }
        $sets = [];
        $models = array_filter($artifacts, static fn (ResolvedArtifact $artifact): bool => $artifact->fileType->id === ModelRelations::MODEL_KIND);
        $classFiles = array_fill_keys(array_values(array_filter($nodes, static fn (string $node): bool => ! str_contains($node, ':'), ARRAY_FILTER_USE_KEY)), true);
        foreach ($models as $path => $model) {
            $direct = array_values(array_filter($adjacent[$path] ?? [], static fn (string $file): bool => ! isset($models[$file])));
            $files = [$path, ...$direct];
            foreach ($direct as $file) {
                if (! isset($classFiles[$file])) {
                    continue;
                }
                // One outward hop finds requests/pages/helpers. Procedural callers (routes and
                // migrations) are terminal members; neither they nor shared bases join seeds.
                $callers = array_filter($adjacent[$file] ?? [], static fn (string $caller): bool => ! isset($classFiles[$caller]));
                foreach ([...($outgoing[$file] ?? []), ...$callers] as $target) {
                    if (! isset($models[$target])) {
                        $files[] = $target;
                    }
                }
            }
            $files = array_values(array_unique($files));
            if (count($files) > 1) {
                $sets[] = [...$this->set('model', $files, $artifacts), 'anchor' => $path];
            }
        }
        $seen = [];
        foreach (array_keys($adjacent) as $path) {
            $queue = [$path];
            $files = [];
            while ($queue !== []) {
                $next = array_pop($queue);
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $files[] = $next;
                array_push($queue, ...($adjacent[$next] ?? []));
            }
            if (count($files) > 1) {
                $sets[] = $this->set('references', $files, $artifacts);
            }
        }
        $folders = [];
        foreach ($nodes as $node => $path) {
            if (! str_starts_with($node, 'file:') || isset($artifacts[$path])) {
                $folders[dirname($path)][$path] = $path;
            }
        }
        foreach ($folders as $files) {
            if (count($files) > 1) {
                $sets[] = $this->set('folder', array_values($files), $artifacts);
            }
        }

        $recurring = [];
        foreach ($sets as $set) {
            $types = $set['types'];
            sort($types, SORT_STRING);
            $key = $set['basis'].'|'.implode('|', $types);
            $recurring[$key] ??= ['basis' => $set['basis'], 'types' => $types, 'occurrences' => []];
            $recurring[$key]['occurrences'][] = $set['files'];
        }

        return ['sets' => $sets, 'recurring_sets' => array_values(array_filter($recurring, static fn (array $item): bool => count($item['occurrences']) > 1))];
    }

    /** @param list<string> $files
     * @param  array<string, ResolvedArtifact>  $artifacts
     * @return array{basis: string, files: list<string>, types: list<string>}
     */
    private function set(string $basis, array $files, array $artifacts): array
    {
        $types = [];
        foreach ($files as $path) {
            if (isset($artifacts[$path])) {
                $types[] = $artifacts[$path]->fileType->id;
            }
        }

        return ['basis' => $basis, 'files' => $files, 'types' => array_values(array_unique($types))];
    }
}
