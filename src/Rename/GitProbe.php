<?php

namespace Tey\Mod\Rename;

use Symfony\Component\Process\Process;
use Tey\Mod\Support\Path;

/** @internal Read-only even when Git normally refreshes the index on status. */
final class GitProbe
{
    public function inspect(string $basePath): GitState
    {
        $root = $this->read($basePath, ['rev-parse', '--show-toplevel']);
        if ($root === null) {
            return new GitState(null, null, null, '', null, []);
        }
        $directory = $this->read($basePath, ['rev-parse', '--absolute-git-dir']);
        $index = $this->read($basePath, ['rev-parse', '--path-format=absolute', '--git-path', 'index']);
        $paths = $this->read($basePath, ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], false) ?? '';
        $prefix = Path::relative(trim($root), $basePath);
        $files = [];
        foreach (explode("\0", $paths) as $path) {
            if ($path === '') {
                continue;
            }
            $relative = $prefix === null || $prefix === '' ? $path : Path::relative($prefix, $path);
            if ($relative !== null) {
                $files[] = Path::normalize($relative);
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return new GitState(Path::normalize(trim($root)), $directory === null ? null : Path::normalize(trim($directory)), $index === null ? null : Path::normalize(trim($index)), $this->read($basePath, ['status', '--porcelain=v1', '-z', '--untracked-files=all'], false) ?? 'Git status unavailable', $index !== null && is_file(trim($index)) ? hash_file('sha256', trim($index)) ?: null : null, $files);
    }

    /** @param list<string> $arguments */
    private function read(string $directory, array $arguments, bool $trim = true): ?string
    {
        $process = new Process(['git', '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false', '-C', $directory, ...$arguments], env: ['GIT_OPTIONAL_LOCKS' => '0']);
        Diagnostic::measure('probe '.$arguments[0], fn () => $process->run());

        return $process->isSuccessful() ? ($trim ? trim($process->getOutput()) : $process->getOutput()) : null;
    }
}
