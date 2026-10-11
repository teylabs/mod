<?php

namespace Tey\Mod\Survey;

use FilesystemIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal Read only local git metadata, without inherited config, helpers or tree discovery. */
final readonly class GitIdentity
{
    public function __construct(private Scope $scope) {}

    /**
     * @param  list<string>  $files
     * @return array{branch: ?string, commit: ?string, dirty: ?bool, remote: ?string, root_commit: ?string}
     */
    public function read(array $files): array
    {
        $empty = ['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null];
        // Linked worktrees and separate git dirs would cross the app's boundary.
        if (is_link($this->scope->root.'/.git') || ! is_dir($this->scope->root.'/.git')) {
            return $empty;
        }
        try {
            if (! $this->safeMetadata($this->scope->root.'/.git')) {
                return $empty;
            }
            $config = $this->scope->root.'/.git/config';
            $text = is_file($config) ? (string) file_get_contents($config) : '';
            if (preg_match('/^\s*\[\s*(?:include|includeIf)\b/im', $text) === 1) {
                return $empty;
            }
            $commit = $this->git(['rev-parse', '--verify', 'HEAD']);
            $roots = explode("\n", $this->git(['rev-parse', '--is-shallow-repository']) === 'true' ? '' : ($this->git(['rev-list', '--max-parents=0', 'HEAD']) ?? ''));
            sort($roots, SORT_STRING);
            $remote = $this->git(['config', '--local', '--no-includes', '--get', 'remote.origin.url']);
            // A URL's credentials, query and fragment are not app identity.
            if ($remote !== null) {
                $remote = preg_replace('~(://)[^/@]+@~', '$1', $remote);
                $remote = preg_replace('/[?#].*$/', '', $remote ?? '');
            }

            return ['branch' => $this->git(['symbolic-ref', '--quiet', '--short', 'HEAD']), 'commit' => $commit,
                'dirty' => $this->dirty($files, $commit), 'remote' => $remote, 'root_commit' => $roots[0] === '' ? null : $roots[0]];
        } catch (Throwable) {
            return $empty;
        }
    }

    private function safeMetadata(string $directory): bool
    {
        /** @var SplFileInfo $entry */
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink() || in_array($entry->getFilename(), ['commondir', 'alternates', 'http-alternates', 'config.worktree'], true)) {
                return false;
            }
            if ($entry->isDir() && ! $this->safeMetadata($entry->getPathname())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare index stat data for protected files: git status may open tracked secrets.
     * A metadata-only change is conservatively dirty until git refreshes its index.
     *
     * @param  list<string>  $files
     */
    private function dirty(array $files, ?string $commit): ?bool
    {
        $index = $this->git(['ls-files', '--stage', '--debug', '-z']);
        if ($index === null) {
            return null;
        }
        if ($commit !== null && $this->git(['diff-index', '--cached', '--name-only', '--no-renames', 'HEAD', '--']) !== '') {
            return true;
        }
        $tracked = [];
        $offset = 0;
        while (preg_match('/\G\d+ ([0-9a-f]+) [0-3]\t([^\x00]+)\x00  ctime: (\d+):\d+\n  mtime: (\d+):\d+\n  dev: \d+\s+ino: \d+\n  uid: \d+\s+gid: \d+\n  size: (\d+)\s+flags: [0-9a-f]+\n/', $index, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $tracked[] = $match[2];
            $path = $this->scope->resolve($match[2]);
            clearstatcache(true, $path);
            $stat = @stat($path);
            if ($stat === false || $stat['mtime'] !== (int) $match[4] || $stat['size'] !== (int) $match[5]
                || (PHP_OS_FAMILY !== 'Windows' && $stat['ctime'] !== (int) $match[3])) {
                return true;
            }
            // Catch same-size, same-second edits without invoking Git clean filters or helpers.
            // Only files admitted by the safe walker may be opened, never protected/index-only paths.
            if (in_array($match[2], $files, true)) {
                $bytes = $this->scope->read($match[2]);
                $algorithm = strlen($match[1]) === 64 ? 'sha256' : 'sha1';
                if (hash($algorithm, 'blob '.strlen($bytes)."\0".$bytes) !== $match[1]) {
                    $normalized = str_replace("\r\n", "\n", $bytes);
                    if (hash($algorithm, 'blob '.strlen($normalized)."\0".$normalized) !== $match[1]) {
                        return true;
                    }
                }
            }
        }
        if ($offset !== strlen($index)) {
            return null;
        }
        $untracked = array_values(array_diff($files, $tracked));
        if ($untracked === []) {
            return false;
        }
        $ignored = $this->git(['check-ignore', '--no-index', '-z', '--stdin'], implode("\0", $untracked)."\0");

        return array_diff($untracked, explode("\0", $ignored ?? '')) !== [];
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, ?string $input = null): ?string
    {
        $env = [];
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'GIT_')) {
                $env[$key] = false;
            }
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $env = [...$env, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $null, 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_LAZY_FETCH' => '1', 'GIT_TERMINAL_PROMPT' => '0'];
        $process = new Process(['git', '--git-dir='.$this->scope->root.'/.git', '--work-tree='.$this->scope->root,
            '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false', '-c', 'core.excludesFile='.$null,
            '-c', 'core.attributesFile='.$null, '-c', 'core.hooksPath='.$null, '-c', 'protocol.allow=never', ...$arguments], $this->scope->root, $env, $input, 10);
        $process->run();

        return $process->isSuccessful() ? (in_array('-z', $arguments, true) ? $process->getOutput() : rtrim($process->getOutput(), "\r\n")) : null;
    }
}
