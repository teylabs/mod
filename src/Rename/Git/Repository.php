<?php

namespace Tey\Mod\Rename\Git;

use RuntimeException;
use Tey\Mod\Rename\GitProbe;
use Tey\Mod\Rename\GitState;
use Tey\Mod\Rename\Process;

/** @internal Git owns worktree discovery and index entry encoding. */
final readonly class Repository
{
    public GitState $state;

    public function __construct(public string $basePath)
    {
        $this->state = (new GitProbe)->inspect($basePath);
        if ($this->state->root === null) {
            throw new RuntimeException('mod:rename requires a Git repository. Initialise and commit the project before retrying. Nothing was written.');
        }
        if ($this->state->directory === null || $this->state->index === null || $this->state->root !== str_replace('\\', '/', realpath($basePath) ?: $basePath)) {
            throw new RuntimeException('mod:rename requires a Git repository rooted at the project. Initialise and commit the project before retrying. Nothing was written.');
        }
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, ?string $input = null): string
    {
        $process = new Process(['git', '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false', ...$arguments], $this->basePath, ['GIT_OPTIONAL_LOCKS' => '0']);
        $process->setInput($input);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return array<string, string> */
    public function entries(): array
    {
        $entries = [];
        foreach (explode("\0", $this->run(['ls-files', '--stage', '-z'])) as $entry) {
            if ($entry !== '') {
                [$value, $path] = explode("\t", $entry, 2);
                if (isset($entries[$path])) {
                    throw new RuntimeException('mod:rename cannot restore an unmerged index. Resolve the index conflict first.');
                }
                $entries[$path] = $value;
            }
        }
        ksort($entries);

        return $entries;
    }
}
