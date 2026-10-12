<?php

namespace Tey\Mod\Survey;

use Illuminate\Foundation\Application;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Reverse\ReverseMapper;

/**
 * @internal Compare safe structural evidence with an uninstalled proposal.
 *
 * @phpstan-import-type Structure from PhpStructure
 * @phpstan-import-type Member from FitProposal
 */
final readonly class AppFit
{
    public function __construct(private Application $app) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(array $input): array
    {
        $scope = new Scope($this->app->basePath());
        $proposal = FitProposal::from($input, $scope);
        $parser = new PhpStructure;
        $files = $scope->files();
        $structures = [];
        $unparsed = [];
        $vendor = [];
        foreach ($files as $path) {
            if (! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            $structures[$path] = $parser->read($scope->read($path), $path);
            if (! $structures[$path]['parsed']) {
                $unparsed[] = $path;
            }
            foreach ($structures[$path]['edges'] as $edge) {
                $vendorPath = $scope->vendorPath($edge['to']);
                if ($vendorPath !== null) {
                    $vendor[$edge['to']] = ['class' => $edge['to'], 'path' => $vendorPath];
                }
            }
        }
        $owned = [];
        $conflicts = [];
        $pages = $proposal->layout !== null ? (new PageStructure)->read($files, $proposal->layout) : [];
        $nonEntries = [];
        foreach ($pages as $path => $page) {
            if ($page['name'] !== null && ! $page['entry']) {
                $nonEntries[] = ['path' => $path, 'name' => $page['name']];
            }
        }
        if ($proposal->layout !== null) {
            $mapper = new ReverseMapper($proposal->layout);
            foreach ($files as $path) {
                $match = $mapper->fromPath($path);
                if ($match->artifact !== null) {
                    $owned[$path] = ['path' => $path, 'file_type' => $match->artifact->fileType->id];
                    $expected = $match->artifact->fqcn();
                    if ($expected !== null && isset($structures[$path]) && ! in_array($expected, $structures[$path]['classes'], true)) {
                        $conflicts[] = ['path' => $path, 'reason' => 'class-mismatch', 'expected' => $expected, 'found' => $structures[$path]['classes']];
                    }
                } elseif ($match->outcome->value !== 'not-owned') {
                    $conflicts[] = ['path' => $path, 'reason' => $match->outcome->value];
                }
            }
        }
        $variances = [];
        $missing = [];
        $memberPaths = [];
        $claims = [];
        $folderClaims = [];
        $annotations = array_column($proposal->variances, null, 'member');
        foreach ($proposal->members as $member) {
            $id = $member['id'];
            $expected = $member['path'];
            $annotation = $annotations[$id] ?? null;
            if ($annotation !== null && ! in_array($annotation['path'], $files, true)) {
                throw new ModException('Variance evidence must be an existing file in the permitted base_path() scan; symlinks and skipped files are refused.');
            }
            $candidates = isset($member['pattern']) && $proposal->layout !== null
                ? $this->folderCandidates($member, $files, $proposal->layout)
                : ($annotation !== null ? [$annotation['path']] : $this->candidates($member, $files, $structures));
            if (isset($member['pattern']) && $candidates !== []) {
                foreach ($candidates as $found) {
                    if (isset($folderClaims[$found])) {
                        $conflicts[] = ['member' => $id, 'path' => $found, 'reason' => 'member-overlap', 'with' => $folderClaims[$found]];

                        continue;
                    }
                    $memberPaths[$found][] = $id;
                    $folderClaims[$found] = $id;
                    $owned[$found] = [...($owned[$found] ?? ['path' => $found]), 'member' => $claims[$found] ?? $id];
                }

                continue;
            }
            if (count($candidates) > 1) {
                $conflicts[] = ['member' => $id, 'path' => $expected, 'reason' => 'ambiguous-member', 'candidates' => $candidates];

                continue;
            }
            if ($candidates === []) {
                $missing[] = ['member' => $id, 'path' => $expected, 'can_mark_variance' => ! isset($member['pattern']),
                    ...(isset($member['pattern']) ? ['pattern' => $member['pattern']] : [])];

                continue;
            }
            $found = $candidates[0];
            if (isset($member['page'], $pages[$found]['name']) && ! $pages[$found]['entry']) {
                $conflicts[] = ['member' => $id, 'path' => $found, 'reason' => 'page-non-entry', 'target' => 'page:'.$member['page']];

                continue;
            }
            if (isset($claims[$found]) && ! isset($annotation['mechanism'])) {
                $conflicts[] = ['member' => $id, 'path' => $found, 'reason' => 'member-overlap', 'with' => $claims[$found]];

                continue;
            }
            if ($annotation === null && $found === $expected && isset($member['class'])
                && ! in_array($member['class'], $structures[$found]['classes'] ?? [], true)) {
                $conflicts[] = ['member' => $id, 'path' => $found, 'reason' => 'class-mismatch', 'expected' => $member['class'], 'found' => $structures[$found]['classes'] ?? []];

                continue;
            }
            $memberPaths[$found][] = $id;
            if (! isset($annotation['mechanism'])) {
                $claims[$found] = $id;
            }
            if ($found !== $expected || $annotation !== null) {
                $variances[] = ['member' => $id, 'expected_path' => $expected, 'path' => $found,
                    'reason' => isset($annotation['mechanism']) ? 'different-mechanism' : 'different-path',
                    'evidence' => $annotation !== null ? 'agent' : 'structure',
                    ...(isset($annotation['mechanism']) ? ['mechanism' => $annotation['mechanism']] : [])];
            } else {
                $owned[$found] = [...($owned[$found] ?? ['path' => $found]), 'member' => $id];
            }
        }
        // Attribute edges to actual matched declarations, including relocated members.
        $targets = [];
        foreach ($memberPaths as $path => $ids) {
            foreach ($structures[$path]['classes'] ?? [] as $class) {
                $targets[strtolower($class)] = $ids;
            }
            if (isset($folderClaims[$path]) && ($pages[$path]['entry'] ?? false) && $pages[$path]['name'] !== null) {
                $targets['page:'.$pages[$path]['name']] = [$folderClaims[$path]];
            }
            foreach ($proposal->members as $member) {
                if (in_array($member['id'], $ids, true) && isset($member['page'])) {
                    $key = 'page:'.$member['page'];
                    $targets[$key] = isset($folderClaims[$path]) ? [...($targets[$key] ?? []), $member['id']] : [$member['id']];
                }
            }
        }
        $outside = [];
        $configuration = [];
        $nonEntryNames = array_column($nonEntries, 'path', 'name');
        foreach ($structures as $path => $structure) {
            foreach ($structure['edges'] as $edge) {
                if ($edge['kind'] === 'page' && isset($nonEntryNames[substr($edge['to'], strlen('page:'))])) {
                    $conflicts[] = ['path' => $path, 'reason' => 'render-non-entry', 'target' => $edge['to'],
                        'page_path' => $nonEntryNames[substr($edge['to'], strlen('page:'))]];
                }
            }
            if (! isset($memberPaths[$path])) {
                foreach ($structure['edges'] as $edge) {
                    $ids = $targets[str_starts_with($edge['to'], 'page:') ? $edge['to'] : strtolower($edge['to'])] ?? [];
                    foreach ($ids as $id) {
                        $outside[] = ['path' => $path, 'recipe' => $proposal->recipe, 'member' => $id, 'kind' => $edge['kind'], 'target' => $edge['to']];
                    }
                }
                foreach ($structure['tables'] as $table) {
                    if (in_array($table['name'], $proposal->tables, true)) {
                        $outside[] = ['path' => $path, 'recipe' => $proposal->recipe, 'kind' => 'table', 'target' => $table['name'], 'line' => $table['line'], 'operation' => $table['operation']];
                    }
                }
            }
            foreach ($structure['callbacks'] as $callback) {
                $members = [];
                foreach ($callback['references'] as $reference) {
                    if (isset($targets[strtolower($reference)])) {
                        array_push($members, ...$targets[strtolower($reference)]);
                    }
                }
                $configuration[] = ['path' => $path, 'line' => $callback['line'], 'target' => $callback['target'],
                    'ownership' => 'unowned', 'recipe' => $members !== [] || isset($memberPaths[$path]) ? $proposal->recipe : null,
                    'members' => array_values(array_unique($members))];
            }
        }
        $unowned = array_values(array_diff($files, array_keys($owned), array_keys($memberPaths)));

        return $this->sorted([
            'recipe' => $proposal->recipe, 'owned' => array_values($owned), 'unowned' => $unowned,
            'conflicts' => $conflicts, 'variances' => $variances, 'missing' => $missing,
            'outside' => $outside, 'configuration' => $configuration, 'vendor' => array_values($vendor),
            'skipped' => $scope->skipped, 'unparsed' => $unparsed, 'non_entries' => $nonEntries,
        ]);
    }

    /** @param Member $member
     * @param  list<string>  $files
     * @param  array<string, Structure>  $structures
     * @return list<string>
     */
    private function candidates(array $member, array $files, array $structures): array
    {
        if (in_array($member['path'], $files, true)) {
            return [$member['path']];
        }
        $candidates = [];
        foreach ($files as $path) {
            if (isset($member['class']) && in_array($member['class'], $structures[$path]['classes'] ?? [], true)) {
                $candidates[] = $path;
            }
        }
        if ($candidates !== []) {
            return $candidates;
        }
        // Prefer the longest identical path tail, ignoring migration timestamps.
        // Ties remain conflicts; renamed roles need an explicit agent variance.
        $basename = static fn (string $path): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path));
        $expected = array_reverse(explode('/', dirname($member['path']).'/'.$basename($member['path'])));
        $ranked = [];
        foreach ($files as $path) {
            $parts = array_reverse(explode('/', dirname($path).'/'.$basename($path)));
            $score = 0;
            foreach ($expected as $i => $part) {
                if (($parts[$i] ?? null) !== $part) {
                    break;
                }
                $score++;
            }
            if ($score > 0) {
                $ranked[$score][] = $path;
            }
        }

        return $ranked === [] ? [] : $ranked[max(array_keys($ranked))];
    }

    /** @param array{id: string, path: string, pattern: string} $member
     * @param  list<string>  $files
     * @return list<string>
     */
    private function folderCandidates(array $member, array $files, CompiledLayout $layout): array
    {
        $regex = '';
        $paths = [
            'pages' => $layout->frontend()['pages'],
            'components' => $layout->frontend()['components'],
            'views' => $layout->frontend()['views'],
        ];
        $template = $paths[$member['path']].'/'.$member['pattern'];
        foreach (explode('/', $template) as $segment) {
            if (preg_match('/^\{(\w+)(\?)?\}$/D', $segment, $token) === 1) {
                $multi = false;
                foreach ($layout->dimensions() as $dimension) {
                    if ($dimension->name === $token[1]) {
                        $multi = $dimension->multi;
                    }
                }
                $capture = '/'.($multi ? '.+' : '[^/]+');
                $regex .= isset($token[2]) ? '(?:'.$capture.')?' : $capture;
            } else {
                $regex .= $segment === '**' ? '(?:/[^/]+)*' : '/'.str_replace('\\*', '[^/]*', preg_quote($segment, '~'));
            }
        }

        return array_values(array_filter($files, static fn (string $path): bool => preg_match('~^'.ltrim($regex, '/').'/.+$~D', $path) === 1));
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function sorted(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->sorted($value);
            }
        }
        unset($value);
        if (array_is_list($data)) {
            $data = array_values(array_unique($data, SORT_REGULAR));
            usort($data, static fn (mixed $a, mixed $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        } else {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
