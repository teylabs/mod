<?php

namespace Tey\Mod\Generation;

use Closure;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;

/**
 * The group folders a placement walks into ("app/Modules/Knowledge"),
 * compared with the folders that exist, level by level:
 *
 *  - case: one existing folder differs only by case (knowledge → Knowledge);
 *  - cases: several do (only on a case-sensitive disk);
 *  - near: none does, but one or more are a near miss (Knowledg → Knowledge):
 *    at most 2 edits, ignoring case, and no more than a third of the name's length;
 *  - new: the folder is new.
 *
 * @internal used by the mod:* generators
 */
final readonly class GroupFolders
{
    /**
     * @param  (Closure(string): list<string>)|null  $folders  the folders directly inside a directory
     */
    public function __construct(private string $basePath, private ?Closure $folders = null) {}

    /**
     * The first level of the placement that is not an existing folder, or null.
     *
     * @return array{kind: 'case'|'cases'|'near'|'new', dimension: string, value: string, suggestions: list<string>, existing: list<string>}|null
     */
    public function inspect(PlacementRule $rule, ResolvedArtifact $artifact): ?array
    {
        if (! $rule instanceof TemplateRule || $artifact->context->isEmpty()) {
            return null;
        }

        $path = $rule->root()->path;

        foreach ($rule->segments() as $segment) {
            if ($segment->literal !== null) {
                $path = Path::join($path, $segment->literal);

                continue;
            }

            $dimension = (string) $segment->dimension;
            $value = $artifact->context->get($dimension);

            if ($value === null) {
                continue;
            }

            $parts = explode('/', $value);

            foreach ($parts as $index => $part) {
                $existing = $this->foldersIn(Path::join($this->basePath, $path));

                if (in_array($part, $existing, true)) {
                    $path = Path::join($path, $part);

                    continue;
                }

                $replace = static function (string $folder) use ($parts, $index): string {
                    $parts[$index] = $folder;

                    return implode('/', $parts);
                };

                $cases = array_values(array_filter($existing, static fn (string $folder): bool => strcasecmp($folder, $part) === 0));

                if ($cases !== []) {
                    return ['kind' => count($cases) === 1 ? 'case' : 'cases', 'dimension' => $dimension, 'value' => $value, 'suggestions' => array_map($replace, $cases), 'existing' => $existing];
                }

                $near = $this->nearMisses($part, $existing);

                return ['kind' => $near === [] ? 'new' : 'near', 'dimension' => $dimension, 'value' => $value, 'suggestions' => array_map($replace, $near), 'existing' => $existing];
            }
        }

        return null;
    }

    /**
     * Existing folders within a small edit distance, closest first.
     *
     * @param  list<string>  $existing
     * @return list<string>
     */
    private function nearMisses(string $part, array $existing): array
    {
        $limit = min(2, max(1, intdiv(strlen($part), 3)));
        $distances = [];

        foreach ($existing as $folder) {
            $distance = levenshtein(strtolower($part), strtolower($folder));

            if ($distance > 0 && $distance <= $limit) {
                $distances[$folder] = $distance;
            }
        }

        uksort($distances, static fn (string $a, string $b): int => [$distances[$a], $a] <=> [$distances[$b], $b]);

        return array_map(strval(...), array_keys($distances));
    }

    /**
     * @return list<string> the folders directly inside, sorted
     */
    private function foldersIn(string $directory): array
    {
        if ($this->folders !== null) {
            return ($this->folders)($directory);
        }

        if (! is_dir($directory)) {
            return [];
        }

        $folders = array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($directory.'/'.$entry),
        ));
        sort($folders);

        return $folders;
    }
}
