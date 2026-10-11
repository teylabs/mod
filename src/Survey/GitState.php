<?php

namespace Tey\Mod\Survey;

use Symfony\Component\Process\Process;
use Tey\Mod\Support\Path;
use Throwable;

/**
 * The git ref, dirty state and identity of the application's own repository.
 *
 * Only a repository rooted at the base path counts: git is told not to look
 * in parent folders, so an app inside another repository reports nulls. Every
 * command is local and read-only; no remote is contacted and no lock taken.
 *
 * @internal
 */
final readonly class GitState
{
    /** @return array{branch: ?string, commit: ?string, dirty: ?bool, remote: ?string, root_commit: ?string} */
    public static function read(string $root): array
    {
        $state = ['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null];
        if (! file_exists($root.'/.git')) {
            return $state;
        }
        $top = self::git($root, ['rev-parse', '--show-toplevel']);
        $real = $top === null ? false : realpath($top);
        if ($real === false || ! Path::same(Path::normalize($real), $root)) {
            return $state;
        }
        $state['commit'] = self::git($root, ['rev-parse', '--verify', '--quiet', 'HEAD']);
        $state['branch'] = self::git($root, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
        $status = self::git($root, ['status', '--porcelain', '--untracked-files=normal'], allowEmpty: true);
        $state['dirty'] = $status === null ? null : $status !== '';
        if ($state['commit'] !== null) {
            $roots = preg_split('/\R/', (string) self::git($root, ['rev-list', '--max-parents=0', 'HEAD'])) ?: [];
            $roots = array_values(array_filter($roots));
            sort($roots, SORT_STRING);
            $state['root_commit'] = $roots[0] ?? null;
        }
        $remotes = preg_split('/\R/', (string) self::git($root, ['remote'])) ?: [];
        $remotes = array_values(array_filter($remotes));
        sort($remotes, SORT_STRING);
        $remote = in_array('origin', $remotes, true) ? 'origin' : ($remotes[0] ?? null);
        if ($remote !== null) {
            $url = self::git($root, ['config', '--get', 'remote.'.$remote.'.url']);
            $state['remote'] = $url === null ? null : self::withoutCredentials($url);
        }

        return $state;
    }

    /** A remote URL without any user name, password or token in it. */
    public static function withoutCredentials(string $url): string
    {
        if (preg_match('~^([a-z][a-z0-9+.-]*://)(?:[^/@]*@)?(.*)$~i', $url, $matches) === 1) {
            return $matches[1].$matches[2];
        }

        return $url;
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments, bool $allowEmpty = false): ?string
    {
        $process = new Process(['git', '--no-pager', '-c', 'core.fsmonitor=false', ...$arguments], $root, [
            'GIT_CEILING_DIRECTORIES' => dirname($root),
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_INDEX_FILE' => false,
            'GIT_OBJECT_DIRECTORY' => false,
            'GIT_COMMON_DIR' => false,
        ], null, 30);
        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }
        $output = trim($process->getOutput());
        if (! $process->isSuccessful() || ($output === '' && ! $allowEmpty)) {
            return null;
        }

        return $output;
    }
}
