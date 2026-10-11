<?php

namespace Tey\Mod\Survey;

use Illuminate\Support\Str;

/**
 * Clusters the reference graph into file sets around each model, and finds
 * the sets and names that recur.
 *
 * A node joins a model's set through the graph when it is at most two links
 * away and its name mentions the model, or when a policy or layout relation
 * links it; it joins through its folder when it shares the model's group
 * (the same dimension values) and mentions the model, or sits in a folder
 * named after the model. Other models never join a set.
 *
 * @internal
 *
 * @phpstan-type Node array{type: ?string, name: string, path: ?string, context: array<string, string>}
 * @phpstan-type Edge array{from: string, kind: string, to: string}
 * @phpstan-type Member array{basis: list<string>, id: string, type: ?string}
 */
final readonly class FileSets
{
    public const ANCHOR_TYPE = 'model';

    /**
     * @param  array<string, Node>  $nodes
     * @param  list<Edge>  $edges
     * @return array{sets: list<array{anchor: string, members: list<Member>}>, patterns: array{names: list<array{count: int, pattern: string, type: string}>, sets: list<array{anchors: list<string>, count: int, types: list<string>}>}}
     */
    public static function build(array $nodes, array $edges): array
    {
        /** @var array<string, list<array{0: string, 1: string}>> $links */
        $links = [];
        foreach ($edges as $edge) {
            $links[$edge['from']][] = [$edge['to'], $edge['kind']];
            $links[$edge['to']][] = [$edge['from'], $edge['kind']];
        }
        $sets = [];
        $signatures = [];
        $names = [];
        foreach ($nodes as $anchor => $node) {
            if ($node['type'] !== self::ANCHOR_TYPE) {
                continue;
            }
            $short = class_basename($anchor);
            /** @var array<string, array<string, true>> $bases */
            $bases = [];
            $seen = [$anchor => true];
            $frontier = [$anchor];
            for ($hop = 0; $hop < 2; $hop++) {
                $next = [];
                foreach ($frontier as $id) {
                    foreach ($links[$id] ?? [] as [$other, $kind]) {
                        if (isset($seen[$other]) || ($nodes[$other]['type'] ?? null) === self::ANCHOR_TYPE) {
                            continue;
                        }
                        if (in_array($kind, ['policy', 'relation'], true) || self::mentions($short, $nodes[$other]['name'] ?? $other)) {
                            $seen[$other] = true;
                            $bases[$other]['graph'] = true;
                            $next[] = $other;
                        }
                    }
                }
                $frontier = $next;
            }
            foreach ($nodes as $id => $other) {
                if ($id === $anchor || $other['type'] === self::ANCHOR_TYPE || $other['path'] === null) {
                    continue;
                }
                $grouped = $node['context'] !== [] && $other['context'] === $node['context'] && self::mentions($short, $other['name']);
                if ($grouped || self::inFolderNamedFor($short, $other['path'])) {
                    $bases[$id]['folder'] = true;
                }
            }
            if ($bases === []) {
                continue;
            }
            ksort($bases, SORT_STRING);
            $members = [];
            $types = [self::ANCHOR_TYPE => true];
            foreach ($bases as $id => $basis) {
                $type = $nodes[$id]['type'] ?? null;
                $basis = array_keys($basis);
                sort($basis, SORT_STRING);
                $members[] = ['basis' => $basis, 'id' => $id, 'type' => $type];
                if ($type !== null) {
                    $types[$type] = true;
                    $pattern = self::pattern($short, $nodes[$id]['name'] ?? $id);
                    if ($pattern !== null) {
                        $names[$type."\0".$pattern][$anchor] = true;
                    }
                }
            }
            $sets[] = ['anchor' => $anchor, 'members' => $members];
            $types = array_keys($types);
            sort($types, SORT_STRING);
            $signatures[implode("\0", $types)][] = $anchor;
        }
        usort($sets, static fn (array $a, array $b): int => strcmp($a['anchor'], $b['anchor']));

        $recurringSets = [];
        foreach ($signatures as $signature => $anchors) {
            if (count($anchors) > 1) {
                sort($anchors, SORT_STRING);
                $recurringSets[] = ['anchors' => $anchors, 'count' => count($anchors), 'types' => explode("\0", $signature)];
            }
        }
        usort($recurringSets, static fn (array $a, array $b): int => [$b['count'], implode(',', $a['types'])] <=> [$a['count'], implode(',', $b['types'])]);

        $recurringNames = [];
        foreach ($names as $key => $anchors) {
            if (count($anchors) > 1) {
                [$type, $pattern] = explode("\0", $key, 2);
                $recurringNames[] = ['count' => count($anchors), 'pattern' => $pattern, 'type' => $type];
            }
        }
        usort($recurringNames, static fn (array $a, array $b): int => [$a['type'], $a['pattern']] <=> [$b['type'], $b['pattern']]);

        return ['sets' => $sets, 'patterns' => ['names' => $recurringNames, 'sets' => $recurringSets]];
    }

    /** Whether a name mentions the model, ignoring case and separators. */
    private static function mentions(string $model, string $name): bool
    {
        return str_contains(self::key($name), self::key($model));
    }

    private static function inFolderNamedFor(string $model, string $path): bool
    {
        $folders = explode('/', $path);
        array_pop($folders);
        foreach ($folders as $folder) {
            if (in_array(self::key($folder), [self::key($model), self::key(Str::plural($model))], true)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** The name with the model's name replaced by placeholders, or null when it does not contain it. */
    private static function pattern(string $model, string $name): ?string
    {
        $replacements = [];
        foreach ([[$model, '{Name}', '{name}'], [Str::plural($model), '{Names}', '{names}']] as [$form, $studly, $lower]) {
            $replacements[$form] = $studly;
            $replacements[Str::snake($form)] = $lower;
            $replacements[Str::kebab($form)] = $lower;
        }
        $pattern = strtr($name, $replacements);

        return $pattern === $name ? null : $pattern;
    }
}
