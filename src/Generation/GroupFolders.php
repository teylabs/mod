<?php

namespace Tey\Mod\Generation;

use Closure;
use Tey\Mod\Artifact\NamePolicyKind;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\Segment;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;

/**
 * The group folders a placement walks into ("app/Modules/Knowledge"),
 * compared with the groups that exist, level by level. A folder is a group
 * of a dimension only when it holds the layout there: a kind folder that
 * follows the placeholder in some template (Knowledge/Models), a fixed file
 * (CreateInvoice/Handler.php), or classes of a kind placed right at the
 * placeholder (app/Models/Knowledge/*.php in type-first). Laravel's own
 * app/Http or a feature's shared Models folder are not groups.
 *
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
    public function inspect(CompiledLayout $layout, ResolvedArtifact $artifact): ?array
    {
        $rule = $layout->rule($artifact->kind->id);

        if (! $rule instanceof TemplateRule || $artifact->context->isEmpty()) {
            return null;
        }

        $prefix = [];

        $path = $rule->root()->path;

        foreach ($rule->segments() as $segment) {
            $prefix[] = $segment;

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
                $parent = Path::join($this->basePath, $path);
                $folders = $this->foldersIn($parent);

                if (in_array($part, $folders, true)) {
                    $path = Path::join($path, $part);

                    continue;
                }

                $kindFolders = self::kindFolders($layout, $rule, $prefix, inside: $index > 0);
                $existing = array_values(array_filter(
                    $folders,
                    fn (string $folder): bool => ! in_array($folder, $kindFolders, true) && $this->holds($layout, $rule, $prefix, Path::join($parent, $folder)),
                ));

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
     * Whether a folder holds the layout for the dimension that ends $prefix:
     * some template of the same root that starts like $prefix continues with
     * a kind folder that exists there, a fixed file, a nested group, or ends
     * with classes placed right in it. Catch-all kinds (negative priority) are
     * no evidence.
     *
     * @param  list<Segment>  $prefix
     */
    private function holds(CompiledLayout $layout, TemplateRule $rule, array $prefix, string $folder, int $depth = 0): bool
    {
        if ($depth > 3 || ! is_dir($folder)) {
            return false;
        }

        $last = count($prefix) - 1;

        foreach ($layout->rules() as $candidate) {
            if (! $candidate instanceof TemplateRule || $candidate->priority() < 0 || $candidate->root()->path !== $rule->root()->path || ! self::startsLike($candidate->segments(), $prefix)) {
                continue;
            }

            $segments = $candidate->segments();
            $literals = [];
            $next = null;

            for ($i = $last + 1; $i < count($segments); $i++) {
                if ($segments[$i]->literal === null) {
                    $next = $segments[$i];

                    break;
                }

                $literals[] = $segments[$i]->literal;
            }

            if ($literals !== []) {
                if (is_dir(Path::join($folder, ...$literals))) {
                    return true;
                }

                continue;
            }

            if ($next !== null) {
                foreach ($this->foldersIn($folder) as $inner) {
                    if ($this->holds($layout, $rule, [...$prefix, $next], Path::join($folder, $inner), $depth + 1)) {
                        return true;
                    }
                }

                continue;
            }

            $policy = $layout->kind($candidate->kindId())->namePolicy;

            if ($policy->kind === NamePolicyKind::Fixed ? is_file(Path::join($folder, $policy->value.'.php')) : glob(Path::join($folder, '*.php')) !== []) {
                return true;
            }
        }

        // A multi-folder group ({name+}) also holds the layout in a nested folder.
        if ($prefix[$last]->multi) {
            foreach ($this->foldersIn($folder) as $inner) {
                if ($this->holds($layout, $rule, $prefix, Path::join($folder, $inner), $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The root's own kind folders at the level of the dimension that ends
     * $prefix: inside one of its groups, the folders that follow it in the
     * root's templates (Group/Models); beside its groups, the folders in its
     * place (Models next to the group folders). They are never groups, even
     * when a kind places classes right in the group and so makes them look
     * like one.
     *
     * @param  list<Segment>  $prefix
     * @return list<string>
     */
    private static function kindFolders(CompiledLayout $layout, TemplateRule $rule, array $prefix, bool $inside): array
    {
        $before = $prefix;

        if (! $inside) {
            array_pop($before);
        }

        $level = count($before);
        $folders = [];

        foreach ($layout->rules() as $candidate) {
            if (! $candidate instanceof TemplateRule || $candidate->root()->path !== $rule->root()->path || ! self::startsLike($candidate->segments(), $before)) {
                continue;
            }

            $literal = ($candidate->segments()[$level] ?? null)?->literal;

            if ($literal !== null) {
                $folders[] = $literal;
            }
        }

        return array_values(array_unique($folders));
    }

    /**
     * @param  list<Segment>  $segments
     * @param  list<Segment>  $prefix
     */
    private static function startsLike(array $segments, array $prefix): bool
    {
        foreach ($prefix as $index => $segment) {
            $other = $segments[$index] ?? null;

            if ($other === null || $other->literal !== $segment->literal || $other->dimension !== $segment->dimension) {
                return false;
            }
        }

        return true;
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
