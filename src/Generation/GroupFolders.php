<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Placement\PlacementRule;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;

/**
 * The group folders a placement walks into ("app/Modules/Knowledge"): a value
 * that differs from an existing folder only by case is refused, and a value
 * that starts a new folder is reported with the folders that exist.
 *
 * @internal used by the mod:* generators
 */
final readonly class GroupFolders
{
    public function __construct(private string $basePath) {}

    /**
     * @return array{0: ?string, 1: ?string} the refusal, or the new-group notice
     */
    public function check(PlacementRule $rule, ResolvedArtifact $artifact): array
    {
        if (! $rule instanceof TemplateRule || $artifact->context->isEmpty()) {
            return [null, null];
        }

        $path = $rule->root()->path;
        $notice = null;

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
                $existing = $this->folders(Path::join($this->basePath, $path));

                if (in_array($part, $existing, true)) {
                    $path = Path::join($path, $part);

                    continue;
                }

                foreach ($existing as $folder) {
                    if (strcasecmp($folder, $part) === 0) {
                        $meant = $parts;
                        $meant[$index] = $folder;

                        return [ucfirst($dimension)." [{$value}] doesn't exist; did you mean [".implode('/', $meant).']?', null];
                    }
                }

                $notice ??= "Created new {$dimension} {$value}".($existing === [] ? '' : ' (existing: '.implode(', ', $existing).')').'.';
                array_splice($parts, 0, $index);
                $path = Path::join($path, ...$parts);

                break;
            }
        }

        return [null, $notice];
    }

    /**
     * @return list<string> the folders directly inside, sorted
     */
    private function folders(string $directory): array
    {
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
