<?php

namespace Tey\Mod\Rename\Git;

use Closure;
use RuntimeException;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\GitProbe;
use Tey\Mod\Rename\PathGuard;
use Tey\Mod\Rename\Recovery\Journal;
use Tey\Mod\Rename\RecoveryInspector;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Rename\Snapshot;
use Tey\Mod\Support\Path;
use Throwable;

/** @internal Sole owner of application mutation and compare-before-restore. */
final class Transaction implements Executor, RecoveryInspector
{
    private ?Closure $hook;

    /** @param null|callable(string): void $hook Deterministic fault/barrier seam, never an environment flag. */
    public function __construct(private readonly string $basePath, ?callable $hook = null)
    {
        $this->hook = $hook === null ? null : Closure::fromCallable($hook);
    }

    public function inspect(Request $request): Result
    {
        $plan = new Plan('mod:rename');
        $plan->wouldWrite = false;
        try {
            $this->validateRequest($request);
            $journal = new Journal(new Repository($this->basePath));
            $journal->load();
            $operations = $this->pendingPaths($journal);
            $plan->recovery = ['journal' => $journal->path(), 'phase' => $journal->phase, 'operations' => $operations, 'checkpoint' => $journal->operation];
            $plan->warning('Recovery journal: '.$journal->path().' ['.$journal->phase.']. Inspect the remaining file and index operations; pass --recover --yes --no-interaction to restore.', false);
            foreach ($operations as $path) {
                $row = $journal->paths[$path];
                $plan->rename['checklist'][] = ['file' => $path, 'after_file' => $path, 'line' => 1, 'category' => 'recovery', 'message' => $row['original'] === null ? 'Remove transaction-created file after comparing current bytes.' : 'Restore original bytes and permissions after comparing current bytes.', 'suggestion' => null];
            }
            foreach (in_array($journal->phase, ['committed', 'restored'], true) ? [] : $this->conflicts($journal) as $conflict) {
                $plan->warning($conflict);
            }
            $plan->wouldWrite = count($plan->warnings) === 1;
        } catch (RuntimeException $error) {
            $plan->warning($error->getMessage());
        }

        return new Result($plan);
    }

    public function execute(Request $request, callable $build, callable $show, callable $confirm): int
    {
        return $this->locked(function (Repository $repository) use ($request, $build, $show, $confirm): int {
            $journal = new Journal($repository);
            $journal->flushed = fn () => $this->checkpoint('journal-temporary:'.$journal->operation);
            if (file_exists($journal->path())) {
                throw new RuntimeException('mod:rename has an interrupted transaction. Inspect --recover --dry-run --json, then run --recover. Journal: '.$journal->path().'. Nothing was written.');
            }
            $result = $build($request);
            $show($result);
            if (! $result->plan->wouldWrite || $result->inputs === null) {
                return 1;
            }
            $inputs = $result->inputs;
            $this->validateOutputs($result);
            if (! $confirm()) {
                return $request->interactive ? 0 : 1;
            }
            // Rebuild under the same lock: definition, contributor dependencies and
            // output must still describe the reviewed operation, before using the reviewed output bytes.
            $fresh = $build($inputs->request);
            if ($fresh->inputs === null || ! (new Snapshot)->unchanged($inputs, $fresh->inputs->definitionHash) || $fresh->bodies !== $result->bodies || $fresh->plan->rename !== $result->plan->rename || ! $fresh->plan->wouldWrite) {
                throw new RuntimeException('mod:rename inputs changed after the preview. Run the preview again. Nothing was written.');
            }
            $journal->originalEntries = $repository->entries();
            $journal->indexMode = (int) fileperms((string) $repository->state->index) & 0777;
            $journal->index = base64_encode((string) file_get_contents((string) $repository->state->index));
            foreach ($result->plan->rename['moves'] as $move) {
                $file = $inputs->files[$move['from']];
                $this->remember($journal, $move['from'], $file->bytes, $file->mode, null);
                $this->remember($journal, $move['to'], null, $file->mode, $file->bytes);
                $journal->entries[$move['to']][] = $journal->originalEntries[$move['from']] ?? null;
            }
            foreach ($result->bodies as $path => $body) {
                if ($body !== $inputs->files[$path]->bytes) {
                    $after = $inputs->afterPath($path);
                    $this->remember($journal, $after, $after === $path ? $inputs->files[$path]->bytes : null, $inputs->files[$path]->mode, $body);
                }
            }
            foreach ($result->generated as $file) {
                $this->remember($journal, $file->path, null, $file->mode, $file->bytes);
            }
            foreach ($journal->paths as $path => $row) {
                if (! str_ends_with($path, '.mod-rename-tmp')) {
                    $temporary = $path.'.mod-rename-tmp';
                    $this->assertFile($temporary, null, 0);
                    $journal->paths[$temporary] = ['original' => null, 'mode' => $row['mode'], 'states' => [null, ...$row['states']]];
                    $journal->entries[$temporary] = [null];
                }
            }
            foreach ($journal->paths as $path => $row) {
                foreach ($row['states'] as $bytes) {
                    if ($bytes !== null && ! str_ends_with($path, '.mod-rename-tmp')) {
                        $hash = trim($repository->run(['hash-object', '-w', '--path='.$path, '--stdin'], (string) base64_decode($bytes, true)));
                        $journal->entries[$path][] = $this->entryMode($journal, $path).' '.$hash.' 0';
                    }
                }
                for ($directory = dirname($path); $directory !== '.' && ! is_dir($this->basePath.'/'.$directory); $directory = dirname($directory)) {
                    $journal->directories[] = $directory;
                }
            }
            foreach ($journal->paths as $path => $row) {
                $journal->expectedFiles[$path] = [$row['original']];
                $journal->expectedEntries[$path] = [$journal->originalEntries[$path] ?? null];
            }
            $journal->directories = array_values(array_unique($journal->directories));
            if (! (new Snapshot)->unchanged($inputs, $fresh->inputs->definitionHash)) {
                throw new RuntimeException('mod:rename inputs changed after the preview. Run the preview again. Nothing was written.');
            }
            $journal->save();
            $operationName = 'preparing the transaction';
            try {
                $this->checkpoint('prepared');
                $journal->phase = 'applying';
                $journal->save();
                $operation = 0;
                foreach ($result->plan->rename['moves'] as $move) {
                    $operationName = 'moving '.$move['from'];
                    $journal->operation = $operationName;
                    $journal->save();
                    $this->assertFile($move['from'], $inputs->files[$move['from']]->bytes, $inputs->files[$move['from']]->mode);
                    $this->assertFile($move['to'], null, 0);
                    $this->parents($move['to']);
                    $this->checkpoint('before-move:'.$move['from']);
                    $this->assertFile($move['from'], $inputs->files[$move['from']]->bytes, $inputs->files[$move['from']]->mode);
                    $this->assertFile($move['to'], null, 0);
                    $currentEntries = $repository->entries();
                    foreach ([$move['from'], $move['to']] as $path) {
                        if (($currentEntries[$path] ?? null) !== ($journal->originalEntries[$path] ?? null)) {
                            throw new RuntimeException('Outside index entry: '.$path);
                        }
                    }
                    $moveFiles = [$move['from'] => null, $move['to'] => base64_encode($inputs->files[$move['from']]->bytes)];
                    $moveEntries = [$move['from'] => null, $move['to'] => $journal->originalEntries[$move['from']] ?? null];
                    $this->intend($journal, $moveFiles, $moveEntries);
                    $repository->run(['mv', '--', $move['from'], $move['to']]);
                    $this->complete($journal, $moveFiles, $moveEntries);
                    $this->checkpoint('applied:'.++$operation);
                }
                foreach ($result->bodies as $path => $body) {
                    $file = $inputs->files[$path];
                    if ($body === $file->bytes) {
                        continue;
                    }
                    $after = $inputs->afterPath($path);
                    $operationName = 'rewriting '.$path;
                    $journal->operation = $operationName;
                    $journal->save();
                    $this->assertFile($after, $file->bytes, $file->mode);
                    $this->checkpoint('before-edit:'.$path);
                    $this->assertFile($after, $file->bytes, $file->mode);
                    $this->intend($journal, [$after => base64_encode($body), $after.'.mod-rename-tmp' => base64_encode($body)]);
                    DurableFile::write($this->basePath.'/'.$after, $body, $file->mode, function () use ($after, $file): void {
                        $this->checkpoint('temporary:'.$after);
                        $this->assertFile($after, $file->bytes, $file->mode);
                    });
                    $this->complete($journal, [$after => base64_encode($body), $after.'.mod-rename-tmp' => null]);
                    $this->checkpoint('applied:'.++$operation);
                }
                foreach ($result->generated as $file) {
                    $operationName = 'creating '.$file->path;
                    $journal->operation = $operationName;
                    $journal->save();
                    $this->assertFile($file->path, null, 0);
                    $this->parents($file->path);
                    $this->checkpoint('before-create:'.$file->path);
                    $this->assertFile($file->path, null, 0);
                    $this->intend($journal, [$file->path => base64_encode($file->bytes), $file->path.'.mod-rename-tmp' => base64_encode($file->bytes)]);
                    DurableFile::write($this->basePath.'/'.$file->path, $file->bytes, $file->mode, function () use ($file): void {
                        $this->checkpoint('temporary:'.$file->path);
                        $this->assertFile($file->path, null, 0);
                    });
                    $this->complete($journal, [$file->path => base64_encode($file->bytes), $file->path.'.mod-rename-tmp' => null]);
                    $this->checkpoint('applied:'.++$operation);
                }
                foreach ($journal->paths as $path => $row) {
                    if (! str_ends_with($path, '.mod-rename-tmp') && file_exists($this->basePath.'/'.$path)) {
                        $operationName = 'staging '.$path;
                        $journal->operation = $operationName;
                        $journal->save();
                        $this->checkpoint('before-stage:'.$path);
                        $final = $row['states'][count($row['states']) - 1];
                        $this->assertFile($path, $final === null ? null : (string) base64_decode($final, true), $row['mode']);
                        $currentEntries = $repository->entries();
                        if (! in_array($currentEntries[$path] ?? null, $journal->entries[$path], true)) {
                            throw new RuntimeException('Outside index entry: '.$path);
                        }
                        $hash = trim($repository->run(['hash-object', '-w', '--path='.$path, '--stdin'], (string) base64_decode((string) $final, true)));
                        $entry = $this->entryMode($journal, $path).' '.$hash.' 0';
                        $this->intend($journal, [], [$path => $entry]);
                        $repository->run(['update-index', '--add', '--cacheinfo', $this->entryMode($journal, $path).','.$hash.','.$path]);
                        $this->complete($journal, [], [$path => $entry]);
                        $this->checkpoint('applied:'.++$operation);
                    }
                }
                if ($this->conflicts($journal) !== []) {
                    throw new RuntimeException('Outside changes detected during rename.');
                }
                foreach ($journal->paths as $path => $row) {
                    $last = $row['states'][count($row['states']) - 1];
                    if (str_ends_with($path, '.mod-rename-tmp')) {
                        $this->assertFile($path, null, 0);
                    } else {
                        $this->assertFile($path, $last === null ? null : (string) base64_decode($last, true), $row['mode']);
                    }
                }
                $this->validateAppliedInputs($result, $journal);
                $journal->phase = 'committed';
                $journal->save();
                $this->checkpoint('committed');
                $this->removeJournal($journal);
                $this->message('Renamed '.$inputs->request->old.' -> '.$inputs->request->new.'. Review the staged diff before committing.', $show);

                return 0;
            } catch (Throwable $error) {
                if ($journal->phase === 'committed') {
                    throw $error;
                }
                try {
                    $this->restore($journal);
                    $this->message('mod:rename failed while '.$operationName.'. All changes were rolled back. Nothing was renamed.', $show);
                } catch (Throwable $rollback) {
                    $this->message('Rollback incomplete; recovery required. Journal: '.$journal->path().'. Remaining operations: '.$rollback->getMessage(), $show);
                    if (! $rollback instanceof RuntimeException) {
                        throw $rollback;
                    }
                }
                if (! $error instanceof RuntimeException) {
                    throw $error;
                }

                return 1;
            }
        }, $show);
    }

    public function recover(Request $request, callable $show, callable $confirm): int
    {
        return $this->locked(function (Repository $repository) use ($request, $show, $confirm): int {
            $this->validateRequest($request);
            $journal = new Journal($repository);
            $journal->flushed = fn () => $this->checkpoint('journal-temporary:'.$journal->operation);
            if (! file_exists($journal->path())) {
                $this->message('mod:rename has no interrupted transaction. Nothing was written.', $show);

                return 0;
            }
            $journal->load();
            $result = $this->inspect($request);
            $show($result);
            if (! $result->plan->wouldWrite) {
                return 1;
            }
            if (! $confirm()) {
                return $request->interactive ? 0 : 1;
            }
            if (! in_array($journal->phase, ['committed', 'restored'], true)) {
                try {
                    $this->restore($journal);
                } catch (RuntimeException $error) {
                    throw new RuntimeException('Rollback incomplete; recovery required. Journal: '.$journal->path().'. Remaining operations: '.$error->getMessage(), previous: $error);
                }
            } else {
                $this->removeJournal($journal);
            }
            $this->message('Recovered interrupted rename. Review the working tree and index before retrying.', $show);

            return 0;
        }, $show);
    }

    /** @return list<string> */
    private function pendingPaths(Journal $journal): array
    {
        if (in_array($journal->phase, ['committed', 'restored'], true)) {
            return [];
        }
        $entries = $journal->repository->entries();
        $paths = [];
        foreach ($journal->paths as $path => $row) {
            $absolute = $this->basePath.'/'.$path;
            $bytes = is_file($absolute) && ! is_link($absolute) ? base64_encode((string) file_get_contents($absolute)) : null;
            if ($bytes !== $row['original'] || ($bytes !== null && ! FileMode::matches((int) fileperms($absolute) & 0777, $row['mode'])) || ($entries[$path] ?? null) !== ($journal->originalEntries[$path] ?? null)) {
                $paths[] = $path;
            }
        }
        sort($paths);

        return $paths;
    }

    private function validateAppliedInputs(Result $result, Journal $journal): void
    {
        $inputs = $result->inputs;
        if ($inputs === null) {
            throw new RuntimeException('Missing execution inputs.');
        }
        foreach ($inputs->files as $path => $file) {
            $after = $inputs->afterPath($path);
            $this->assertFile($after, $result->bodies[$path] ?? $file->bytes, $file->mode);
        }
        foreach ($inputs->dependencies as $path => $hash) {
            $relative = Path::relative($this->basePath, $path);
            if ($relative !== null && isset($inputs->files[$relative])) {
                continue;
            }
            if (! is_file($path) || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('mod:rename read dependency changed during application: '.$path);
            }
        }
        $expected = $inputs->git->paths;
        foreach ($result->plan->rename['moves'] as $move) {
            $expected = array_values(array_diff($expected, [$move['from']]));
            $expected[] = $move['to'];
        }
        foreach ($result->generated as $file) {
            $expected[] = $file->path;
        }
        sort($expected);
        $current = (new GitProbe)->inspect($this->basePath);
        if ($current->paths !== $expected || array_diff_key($journal->repository->entries(), $journal->entries) !== array_diff_key($journal->originalEntries, $journal->entries)) {
            throw new RuntimeException('mod:rename tree or index changed outside the transaction during application.');
        }
    }

    private function validateRequest(Request $request): void
    {
        if ($request->old !== null || $request->new !== null || $request->scaffold !== null || $request->answers !== [] || $request->tableMigration) {
            throw new RuntimeException('mod:rename --recover takes no cluster arguments or recipe options. Omit them and retry. Nothing was written.');
        }
    }

    /** @param callable(Repository): int $action
     * @param  callable(Result): void  $show
     */
    private function locked(callable $action, callable $show): int
    {
        $handle = null;
        try {
            $repository = new Repository($this->basePath);
            $directory = $repository->state->directory.'/mod-rename';
            if (is_link($directory)) {
                throw new RuntimeException('mod:rename metadata directory is a symlink. Nothing was written.');
            }
            if (! is_dir($directory) && ! mkdir($directory, 0700)) {
                throw new RuntimeException('mod:rename cannot create its worktree metadata directory.');
            }
            $lock = $directory.'/lock';
            if (is_link($lock)) {
                throw new RuntimeException('mod:rename lock is a symlink. Nothing was written.');
            }
            $handle = fopen($lock, 'c+b');
            if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('mod:rename is already running in this worktree. Wait for it to finish. Nothing was written.');
            }

            return $action($repository);
        } catch (RuntimeException $error) {
            $this->message($error->getMessage(), $show);

            return 1;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function validateOutputs(Result $result): void
    {
        $guard = new PathGuard($this->basePath);
        foreach ($result->plan->rename['moves'] as $move) {
            if (($problem = $guard->problem($move['to'])) !== null) {
                throw new RuntimeException($problem);
            }
            $this->assertFile($move['to'], null, 0);
        }
        foreach ($result->bodies as $path => $body) {
            if ($result->inputs !== null && $body !== $result->inputs->files[$path]->bytes) {
                $this->validatePhp($path, $body);
            }
        }
        foreach ($result->generated as $file) {
            if (($problem = $guard->problem($file->path)) !== null) {
                throw new RuntimeException($problem);
            }
            $this->assertFile($file->path, null, 0);
            $this->validatePhp($file->path, $file->bytes);
        }
    }

    private function validatePhp(string $path, string $body): void
    {
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            try {
                if (token_get_all($body, TOKEN_PARSE) === []) {
                    throw new RuntimeException('mod:rename output PHP is empty at '.$path);
                }
            } catch (\ParseError $error) {
                throw new RuntimeException('mod:rename output PHP is invalid at '.$path.'. Fix the source before retrying. Nothing was written.', previous: $error);
            }
        }
    }

    /** @param array<string, ?string> $files
     * @param  array<string, ?string>  $entries
     */
    private function intend(Journal $journal, array $files = [], array $entries = []): void
    {
        $conflicts = $this->conflicts($journal);
        if ($conflicts !== []) {
            throw new RuntimeException(implode('; ', $conflicts));
        }
        foreach ($files as $path => $state) {
            $journal->expectedFiles[$path][] = $state;
        }
        foreach ($entries as $path => $state) {
            $journal->expectedEntries[$path][] = $state;
        }
        $journal->save();
    }

    /** @param array<string, ?string> $files
     * @param  array<string, ?string>  $entries
     */
    private function complete(Journal $journal, array $files = [], array $entries = []): void
    {
        foreach ($files as $path => $state) {
            $journal->expectedFiles[$path] = [$state];
        }
        foreach ($entries as $path => $state) {
            $journal->expectedEntries[$path] = [$state];
        }
        $journal->save();
    }

    private function entryMode(Journal $journal, string $path): string
    {
        foreach ($journal->entries[$path] as $entry) {
            if ($entry !== null) {
                return substr($entry, 0, 6);
            }
        }

        return ($journal->paths[$path]['mode'] & 0111) !== 0 ? '100755' : '100644';
    }

    private function remember(Journal $journal, string $path, ?string $original, int $mode, ?string $after): void
    {
        $journal->paths[$path] ??= ['original' => $original === null ? null : base64_encode($original), 'mode' => $mode, 'states' => [$original === null ? null : base64_encode($original)]];
        $journal->paths[$path]['states'][] = $after === null ? null : base64_encode($after);
        $journal->entries[$path] ??= [$journal->originalEntries[$path] ?? null];
        $journal->entries[$path][] = null;
    }

    /** @return list<string> */
    private function conflicts(Journal $journal): array
    {
        $conflicts = [];
        $guard = new PathGuard($this->basePath);
        foreach ($journal->paths as $path => $row) {
            $absolute = $this->basePath.'/'.$path;
            if ($guard->problem($path) !== null || (file_exists($absolute) && ! is_file($absolute))) {
                $conflicts[] = 'Outside path change: '.$path;

                continue;
            }
            $bytes = is_file($absolute) ? base64_encode((string) file_get_contents($absolute)) : null;
            if (! (in_array($bytes, $journal->expectedFiles[$path], true) || ($bytes !== null && str_ends_with($path, '.mod-rename-tmp') && array_filter($journal->expectedFiles[$path], static fn (?string $state): bool => $state !== null && str_starts_with((string) base64_decode($state, true), (string) base64_decode($bytes, true))) !== [])) || ($bytes !== null && ! FileMode::matches((int) fileperms($absolute) & 0777, $row['mode']))) {
                $conflicts[] = 'Outside bytes or permissions: '.$path;
            }
        }
        $entries = $journal->repository->entries();
        foreach ($journal->expectedEntries as $path => $allowed) {
            if (! in_array($entries[$path] ?? null, $allowed, true)) {
                $conflicts[] = 'Outside index entry: '.$path;
            }
        }

        return $conflicts;
    }

    private function restore(Journal $journal): void
    {
        $conflicts = $this->conflicts($journal);
        if ($conflicts !== []) {
            throw new RuntimeException(implode('; ', $conflicts));
        }
        $journal->phase = 'rolling-back';
        $journal->save();
        $restorePaths = $journal->paths;
        uksort($restorePaths, static fn (string $a, string $b): int => (int) str_ends_with($b, '.mod-rename-tmp') <=> (int) str_ends_with($a, '.mod-rename-tmp'));
        foreach ($restorePaths as $path => $row) {
            $journal->operation = 'restoring '.$path;
            $journal->save();
            $this->checkpoint('before-restore:'.$path);
            // Recompare at every boundary; repeated recovery accepts already restored bytes.
            if ($this->conflicts($journal) !== []) {
                throw new RuntimeException(implode('; ', $this->conflicts($journal)));
            }
            $absolute = $this->basePath.'/'.$path;
            $restore = [$path => $row['original']];
            if ($row['original'] !== null) {
                $restore[$path.'.mod-rename-tmp'] = $row['original'];
            }
            $this->intend($journal, $restore);
            if ($row['original'] === null) {
                if (is_file($absolute) && ! unlink($absolute)) {
                    throw new RuntimeException('Cannot remove '.$path);
                }
            } else {
                $this->parents($path);
                $previous = is_file($absolute) ? (string) file_get_contents($absolute) : null;
                DurableFile::write($absolute, (string) base64_decode($row['original'], true), $row['mode'], function () use ($path, $previous, $row): void {
                    $this->checkpoint('restore-temporary:'.$path);
                    $this->assertFile($path, $previous, $row['mode']);
                });
            }
            if ($row['original'] !== null) {
                $restore[$path.'.mod-rename-tmp'] = null;
            }
            $this->complete($journal, $restore);
            $this->checkpoint('restored:'.$path);
        }
        $repository = $journal->repository;
        $current = $repository->entries();
        $restoreEntries = [];
        foreach (array_keys($journal->entries) as $path) {
            $restoreEntries[$path] = $journal->originalEntries[$path] ?? null;
        }
        $this->intend($journal, [], $restoreEntries);
        $outside = array_diff_key($current, $journal->entries);
        $originalOutside = array_diff_key($journal->originalEntries, $journal->entries);
        if ($outside === $originalOutside) {
            $index = (string) $repository->state->index;
            $backup = dirname($journal->path()).'/restore-index';
            $bytes = (string) base64_decode($journal->index, true);
            $temporary = $backup.'.mod-rename-tmp';
            if (file_exists($temporary)) {
                if (is_link($temporary) || ! is_file($temporary) || ! str_starts_with($bytes, (string) file_get_contents($temporary))) {
                    throw new RuntimeException('Outside recovery-index temporary bytes.');
                }
                unlink($temporary);
            }
            if (file_exists($backup)) {
                if (is_link($backup) || ! is_file($backup) || file_get_contents($backup) !== $bytes) {
                    throw new RuntimeException('Outside recovery-index backup bytes.');
                }
            } else {
                DurableFile::write($backup, $bytes, $journal->indexMode);
            }
            if (file_exists($index.'.lock')) {
                $owned = stat($backup);
                $locked = lstat($index.'.lock');
                if (is_link($index.'.lock') || $owned === false || $locked === false || $owned['ino'] !== $locked['ino'] || $owned['dev'] !== $locked['dev'] || file_get_contents($index.'.lock') !== $bytes) {
                    throw new RuntimeException('Git index is locked by another owner. Preserve its lock and retry recovery after it finishes.');
                }
                unlink($index.'.lock');
            }
            if ($repository->entries() !== $current || ! @link($backup, $index.'.lock')) {
                throw new RuntimeException('Index changed or locked before restoration. Retry recovery.');
            }
            $this->checkpoint('index-linked');
            if ($repository->entries() !== $current) {
                unlink($index.'.lock');
                throw new RuntimeException('Index changed before restoration. Retry recovery.');
            }
            if (! rename($index.'.lock', $index)) {
                throw new RuntimeException('Cannot restore index.');
            }
            DurableFile::directory(dirname($index));
        } else {
            $input = '';
            $zero = str_repeat('0', strlen(trim($repository->run(['hash-object', '--stdin'], ''))));
            foreach ($journal->entries as $path => $allowed) {
                $input .= '0 '.$zero."\t".$path."\0";
                if (isset($journal->originalEntries[$path])) {
                    $input .= $journal->originalEntries[$path]."\t".$path."\0";
                }
            }
            $repository->run(['update-index', '-z', '--index-info'], $input);
        }
        $this->complete($journal, [], $restoreEntries);
        $directories = $journal->directories;
        usort($directories, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($directories as $directory) {
            $absolute = $this->basePath.'/'.$directory;
            if (is_dir($absolute) && scandir($absolute) === ['.', '..']) {
                rmdir($absolute);
            }
        }
        $journal->phase = 'restored';
        $journal->save();
        $this->checkpoint('restored');
        $this->removeJournal($journal);
    }

    private function assertFile(string $path, ?string $bytes, int $mode): void
    {
        $absolute = $this->basePath.'/'.$path;
        if ((new PathGuard($this->basePath))->problem($path) !== null || ($bytes === null ? file_exists($absolute) : (! is_file($absolute) || file_get_contents($absolute) !== $bytes || ! FileMode::matches((int) fileperms($absolute) & 0777, $mode)))) {
            throw new RuntimeException('mod:rename input changed at '.$path.'. Run the preview again.');
        }
    }

    private function parents(string $path): void
    {
        $directory = dirname($this->basePath.'/'.$path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException('Cannot create '.$directory);
        }
    }

    private function checkpoint(string $phase): void
    {
        if ($this->hook !== null) {
            ($this->hook)($phase);
        }
    }

    /** @param callable(Result): void $show */
    private function message(string $message, callable $show): void
    {
        $plan = new Plan('mod:rename');
        $plan->notice = $message;
        $plan->warning($message);
        $show(new Result($plan));
    }

    private function removeJournal(Journal $journal): void
    {
        $journal->save();
        foreach (['restore-index', 'restore-index.mod-rename-tmp'] as $name) {
            $path = dirname($journal->path()).'/'.$name;
            if (file_exists($path)) {
                $expected = (string) base64_decode($journal->index, true);
                if (is_link($path) || ! is_file($path) || ! str_starts_with($expected, (string) file_get_contents($path)) || ! unlink($path)) {
                    throw new RuntimeException('Cannot safely clean recovery index metadata '.$path);
                }
            }
        }
        $journal->cleanTemporaries();
        if (! unlink($journal->path())) {
            throw new RuntimeException('Cannot remove completed journal '.$journal->path());
        }
        DurableFile::directory(dirname($journal->path()));
    }
}
