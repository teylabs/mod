<?php

namespace Tey\Mod\Rename\Frontend;

use Symfony\Component\Filesystem\Path;
use Tey\Mod\Layout\BuiltIn\ImportContent;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Contributor;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\InputFile;
use Tey\Mod\Rename\Inputs;
use UnexpectedValueException;

/** @internal Read-only frontend contributor. No dependency installation or source writes. */
final class Frontend implements Contributor
{
    public function __construct(private readonly Runner $runner = new NodeRunner) {}

    public function contribute(Inputs $inputs): Contribution
    {
        $paths = [];
        $names = [];
        $tags = [];
        $components = [];
        $aliases = ['@' => 'resources/js'];
        foreach ($inputs->layout->roots() as $root) {
            if (($tokenOffset = strpos($root->path, '{')) !== false) {
                $aliases[ImportContent::ALIAS] = rtrim(substr($root->path, 0, $tokenOffset), '/');
                break;
            }
        }
        foreach (['views', 'css', 'pages', 'components'] as $key) {
            $pattern = $inputs->layout->frontend()[$key];
            if ($pattern !== null && ! str_starts_with($pattern, 'resources/js/') && ($tokenOffset = strpos($pattern, '{')) !== false) {
                $aliases[ImportContent::ALIAS] = rtrim(substr($pattern, 0, $tokenOffset), '/');
                break;
            }
        }
        foreach ($inputs->members as $member) {
            $from = $member->old->path();
            $to = $inputs->afterPath($from);
            if ($from === $to) {
                continue;
            }
            $paths[$from] = $to;
            $import = $member->oldIdentity['import'] ?? '';
            if (str_starts_with($import, ImportContent::ALIAS.'/') && str_ends_with($from, substr($import, strlen(ImportContent::ALIAS.'/')))) {
                $aliases[ImportContent::ALIAS] = rtrim(substr($from, 0, strlen($from) - strlen(substr($import, strlen(ImportContent::ALIAS.'/')))), '/');
            }
            if (in_array(pathinfo($from, PATHINFO_EXTENSION), ['vue', 'js', 'ts', 'jsx', 'tsx'], true) && isset($member->oldIdentity['component'], $member->newIdentity['component']) && $member->oldIdentity['component'] !== $member->newIdentity['component']) {
                $components[$member->oldIdentity['component']] = $member->newIdentity['component'];
            }
            foreach (['name' => &$names, 'tag' => &$tags] as $key => &$map) {
                $old = $member->oldIdentity[$key] ?? null;
                $new = $member->newIdentity[$key] ?? null;
                if ($old !== null && $new !== null && $old !== $new) {
                    $map[$old] = $new;
                }
            }
            unset($map);
        }
        $edits = [];
        $checklist = [];
        $frontend = [];
        foreach ($inputs->files as $file) {
            if ($file->historicalMigration) {
                continue;
            }
            if (str_ends_with($file->path, '.blade.php')) {
                $blade = Blade::contribute($file, $names, $tags);
                array_push($edits, ...$blade->edits);
                array_push($checklist, ...$this->identityReview($file, $names, $blade->edits));

                continue;
            }
            $kind = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
            if (in_array($kind, ['js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'vue', 'css'], true)) {
                if (preg_match('//u', $file->bytes) !== 1) {
                    array_push($checklist, ...$this->fallback($file, $inputs, $paths, $aliases, $names, 'Source is not valid UTF-8.'));

                    continue;
                }
                $frontend[$file->path] = $file;
            }
        }
        if ($frontend === []) {
            return new Contribution($edits, $checklist);
        }
        $payload = json_encode(['version' => 1, 'base' => $inputs->basePath, 'files' => array_values(array_map(static fn (InputFile $file): array => ['path' => $file->path, 'source' => $file->bytes, 'kind' => strtolower(pathinfo($file->path, PATHINFO_EXTENSION))], $frontend)), 'inventory' => array_keys($inputs->files), 'paths' => (object) $paths, 'identities' => (object) $names, 'components' => (object) $components, 'aliases' => $aliases], JSON_THROW_ON_ERROR);
        $result = $this->runner->run($inputs->basePath, $payload);
        $dependencies = $result->dependencies;
        $failures = [];
        if ($result->stdout !== null) {
            try {
                $contribution = Protocol::decode($result->stdout, $frontend);
                array_push($edits, ...$contribution->edits);
                array_push($checklist, ...$contribution->checklist);
                $dependencies += $contribution->dependencies;
                $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded) && is_array($decoded['failures'] ?? null)) {
                    foreach ($decoded['failures'] as $path => $reason) {
                        if (is_string($path) && is_string($reason)) {
                            $failures[$path] = $reason;
                        }
                    }
                }
            } catch (UnexpectedValueException $exception) {
                $failures = array_fill_keys(array_keys($frontend), 'Unsafe helper response: '.$exception->getMessage());
            }
        } else {
            $failures = array_fill_keys(array_keys($frontend), $result->diagnostic);
        }
        foreach ($failures as $path => $reason) {
            array_push($checklist, ...$this->fallback($frontend[$path], $inputs, $paths, $aliases, $names, $reason));
        }
        if ($result->stdout !== null && $result->diagnostic !== '') {
            $checklist[] = new Checklist(array_key_first($frontend), 1, 'frontend-toolchain', 'Node helper diagnostic: '.$result->diagnostic);
        }

        return new Contribution($edits, $checklist, dependencies: $dependencies);
    }

    /** @param array<string, string> $names
     * @param  list<Edit>  $edits
     * @return list<Checklist>
     */
    private function identityReview(InputFile $file, array $names, array $edits = []): array
    {
        $rows = [];
        foreach ($names as $old => $new) {
            $offset = 0;
            while (($offset = strpos($file->bytes, $old, $offset)) !== false) {
                $covered = false;
                foreach ($edits as $edit) {
                    if ($offset >= $edit->offset && $offset < $edit->offset + strlen($edit->before)) {
                        $covered = true;
                    }
                }
                if (! $covered) {
                    $rows[] = new Checklist($file->path, substr_count(substr($file->bytes, 0, $offset), "\n") + 1, 'identity-string', 'Unsupported identity context is unchanged.', $new);
                }
                $offset += strlen($old);
            }
        }

        return $rows;
    }

    /** @param array<string, string> $paths
     * @param  array<string, string>  $aliases
     * @param  array<string, string>  $names
     * @return list<Checklist>
     */
    private function fallback(InputFile $file, Inputs $inputs, array $paths, array $aliases, array $names, string $reason): array
    {
        $rows = [];
        // Detection only: these matches never authorise rewrites.
        preg_match_all('/(?:\b(?:import|export)\b[^;\r\n]*|\burl\s*\([^;)\r\n]*)/', $file->bytes, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$text, $offset]) {
            $suggestion = null;
            if (preg_match('/[\'"]([^\'"\r\n]+)[\'"]/', $text, $literal) === 1) {
                $value = $literal[1];
                $alias = null;
                $resolved = null;
                foreach ($aliases as $name => $root) {
                    if (str_starts_with($value, $name.'/') && ! in_array('..', explode('/', $value), true)) {
                        $alias = $name;
                        $resolved = Path::canonicalize($root.'/'.substr($value, strlen($name) + 1));
                        break;
                    }
                }
                if ($alias === null && (str_starts_with($value, './') || str_starts_with($value, '../'))) {
                    $resolved = Path::canonicalize(dirname($file->path).'/'.$value);
                }
                if ($resolved !== null) {
                    $candidates = [];
                    foreach (['', '.vue', '.js', '.ts', '.jsx', '.tsx', '.mjs', '.cjs', '/index.js', '/index.ts', '/index.tsx', '/index.jsx'] as $suffix) {
                        if (isset($inputs->files[$resolved.$suffix])) {
                            $candidates[] = [$resolved.$suffix, $suffix];
                        }
                    }
                    if (count($candidates) === 1) {
                        [$original, $suffix] = $candidates[0];
                        $target = $paths[$original] ?? $original;
                        if ($suffix !== '' && str_ends_with($target, $suffix)) {
                            $target = substr($target, 0, -strlen($suffix));
                        }
                        if ($alias !== null && str_starts_with($target, $aliases[$alias].'/')) {
                            $suggestion = $alias.'/'.substr($target, strlen($aliases[$alias]) + 1);
                        } elseif ($alias === null) {
                            $relative = Path::makeRelative($target, dirname($inputs->afterPath($file->path)));
                            $suggestion = str_starts_with($relative, '.') ? $relative : './'.$relative;
                        }
                    }
                }
            }
            $rows[] = new Checklist($file->path, substr_count(substr($file->bytes, 0, $offset), "\n") + 1, 'frontend-toolchain', 'Import or asset requires review; '.substr($reason, 0, 1000), $suggestion);
        }
        array_push($rows, ...$this->identityReview($file, $names));
        // A parser failure with no detectable occurrence is still visible.
        if ($rows === []) {
            $rows[] = new Checklist($file->path, 1, 'frontend-toolchain', 'Frontend file requires review; '.substr($reason, 0, 1000));
        }

        return $rows;
    }
}
