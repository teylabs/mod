<?php

namespace Tey\Mod\Rename\Recovery;

use RuntimeException;
use Tey\Mod\Rename\Git\DurableFile;
use Tey\Mod\Rename\Git\Repository;
use Tey\Mod\Rename\PathGuard;
use Tey\Mod\Rename\Snapshot;

/** @internal Untrusted on-disk input is validated before any recovery effect. */
final class Journal
{
    /** @var array<string, array{original: ?string, mode: int, states: list<?string>}> Base64 bytes; null means absent. */
    public array $paths = [];

    /** @var array<string, list<?string>> Allowed transaction index entries. */
    public array $entries = [];

    /** @var array<string, list<?string>> Only states possible at the durable checkpoint. */
    public array $expectedFiles = [];

    /** @var array<string, list<?string>> */
    public array $expectedEntries = [];

    public string $nonce;

    /** @var array<string, string> */
    public array $originalEntries = [];

    /** @var list<string> */
    public array $directories = [];

    public string $index = '';

    public int $indexMode = 0600;

    public string $phase = 'prepared';

    public string $operation = 'preparing the transaction';

    public ?\Closure $flushed = null;

    public function __construct(public readonly Repository $repository)
    {
        $this->nonce = bin2hex(random_bytes(16));
    }

    public function path(): string
    {
        return $this->repository->state->directory.'/mod-rename/journal.json';
    }

    public function save(): void
    {
        $bytes = json_encode(['version' => 1, 'nonce' => $this->nonce, 'root' => realpath($this->repository->basePath), 'index_path' => $this->repository->state->index, 'index' => $this->index, 'index_mode' => $this->indexMode, 'phase' => $this->phase, 'operation' => $this->operation, 'paths' => $this->paths, 'entries' => $this->entries, 'expected_files' => $this->expectedFiles, 'expected_entries' => $this->expectedEntries, 'original_entries' => $this->originalEntries, 'directories' => $this->directories], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($bytes) > 134217728) {
            throw new RuntimeException('mod:rename journal exceeds the supported size. Nothing was written.');
        }
        DurableFile::write($this->path(), $bytes, flushed: $this->flushed, temporary: $this->path().'.mod-rename-tmp.'.$this->nonce.'.'.bin2hex(random_bytes(8)));

    }

    public function load(): void
    {
        $path = $this->path();
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('mod:rename has no usable recovery journal. Nothing was written.');
        }
        if (filesize($path) > 134217728) {
            throw new RuntimeException('mod:rename recovery journal exceeds the supported size. Nothing was written.');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('mod:rename recovery journal is malformed. Nothing was written.', previous: $error);
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_string($data['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/', $data['nonce']) !== 1 || ($data['root'] ?? null) !== realpath($this->repository->basePath) || ($data['index_path'] ?? null) !== $this->repository->state->index || ! is_int($data['index_mode'] ?? null) || $data['index_mode'] < 0 || $data['index_mode'] > 0777 || ! is_string($data['index'] ?? null) || base64_decode($data['index'], true) === false || ! is_string($data['operation'] ?? null) || ! in_array($data['phase'] ?? null, ['prepared', 'applying', 'rolling-back', 'committed', 'restored'], true)) {
            throw new RuntimeException('mod:rename recovery journal is malformed or belongs to another worktree. Nothing was written.');
        }
        $guard = new PathGuard($this->repository->basePath);
        if (! is_array($data['paths'] ?? null) || ! is_array($data['entries'] ?? null) || ! is_array($data['original_entries'] ?? null) || ! is_array($data['directories'] ?? null)) {
            throw new RuntimeException('mod:rename recovery journal has invalid operation metadata. Nothing was written.');
        }
        $paths = [];
        foreach ($data['paths'] as $name => $row) {
            if (! is_string($name) || $guard->problem($name) !== null || (new Snapshot)->excluded($name) || ! is_array($row) || ! array_key_exists('original', $row) || ! self::bytes($row['original']) || ! is_int($row['mode'] ?? null) || $row['mode'] < 0 || $row['mode'] > 0777 || ! is_array($row['states'] ?? null) || ! array_is_list($row['states']) || $row['states'] === []) {
                throw new RuntimeException('mod:rename recovery journal contains an unsafe file operation. Nothing was written.');
            }
            $states = [];
            foreach ($row['states'] as $state) {
                if (! self::bytes($state)) {
                    throw new RuntimeException('mod:rename recovery journal contains invalid bytes. Nothing was written.');
                }
                $states[] = $state;
            }
            $paths[$name] = ['original' => $row['original'], 'mode' => $row['mode'], 'states' => $states];
        }
        $entries = [];
        foreach ($data['entries'] as $name => $values) {
            if (! is_string($name) || ! isset($paths[$name]) || ! is_array($values) || ! array_is_list($values)) {
                throw new RuntimeException('mod:rename recovery journal contains unsafe index operations.');
            }
            $entries[$name] = [];
            foreach ($values as $value) {
                if ($value !== null && (! is_string($value) || preg_match('/^100(?:644|755) [a-f0-9]{40,64} 0$/', $value) !== 1)) {
                    throw new RuntimeException('mod:rename recovery journal contains invalid index entries.');
                }
                $entries[$name][] = $value;
            }
        }
        $originalEntries = [];
        foreach ($data['original_entries'] as $name => $value) {
            if (! is_string($name) || str_contains($name, "\0") || ! is_string($value) || preg_match('/^(?:100644|100755|120000|160000) [a-f0-9]{40,64} 0$/', $value) !== 1) {
                throw new RuntimeException('mod:rename recovery journal contains invalid original index entries.');
            }
            $originalEntries[$name] = $value;
        }
        $directories = [];
        foreach ($data['directories'] as $directory) {
            if (! is_string($directory) || $guard->problem($directory) !== null || (new Snapshot)->excluded($directory)) {
                throw new RuntimeException('mod:rename recovery journal contains unsafe directories.');
            }
            $directories[] = $directory;
        }
        if (array_keys($entries) !== array_keys($paths)) {
            throw new RuntimeException('mod:rename recovery journal has incomplete index metadata.');
        }
        $fileStates = [];
        foreach ($paths as $name => $row) {
            $fileStates[$name] = $row['states'];
        }
        $this->expectedFiles = self::expected($data['expected_files'] ?? null, $fileStates);
        $this->expectedEntries = self::expected($data['expected_entries'] ?? null, $entries);
        $this->nonce = $data['nonce'];
        $this->paths = $paths;
        $this->entries = $entries;
        $this->originalEntries = $originalEntries;
        $this->directories = $directories;
        $rawIndex = (string) base64_decode($data['index'], true);
        $digestLength = strlen($rawIndex) >= 32 && hash_equals(substr($rawIndex, -32), hash('sha256', substr($rawIndex, 0, -32), true)) ? 32 : 20;
        if (! str_starts_with($rawIndex, 'DIRC') || ! hash_equals(substr($rawIndex, -$digestLength), hash($digestLength === 32 ? 'sha256' : 'sha1', substr($rawIndex, 0, -$digestLength), true))) {
            throw new RuntimeException('mod:rename recovery journal has a corrupt original index. Nothing was written.');
        }
        foreach ($paths as $name => $row) {
            if (! in_array($row['original'], $row['states'], true) || ! in_array($originalEntries[$name] ?? null, $entries[$name], true)) {
                throw new RuntimeException('mod:rename recovery journal has inconsistent original states. Nothing was written.');
            }
        }
        $this->index = $data['index'];
        $this->indexMode = $data['index_mode'];
        $this->phase = $data['phase'];
        $this->operation = $data['operation'];
    }

    /** @param array<string, list<?string>> $allowed
     * @return array<string, list<?string>>
     */
    private static function expected(mixed $data, array $allowed): array
    {
        if (! is_array($data) || array_keys($data) !== array_keys($allowed)) {
            throw new RuntimeException('mod:rename recovery journal has incomplete checkpoint states.');
        }
        $result = [];
        foreach ($data as $name => $states) {
            if (! is_string($name) || ! is_array($states) || ! array_is_list($states) || $states === []) {
                throw new RuntimeException('mod:rename recovery journal has invalid checkpoint states.');
            }
            $result[$name] = [];
            foreach ($states as $state) {
                if (($state !== null && ! is_string($state)) || ! in_array($state, $allowed[$name], true)) {
                    throw new RuntimeException('mod:rename recovery journal has foreign checkpoint states.');
                }
                $result[$name][] = $state;
            }
        }

        return $result;
    }

    public function cleanTemporaries(): void
    {
        foreach (glob($this->path().'.mod-rename-tmp.'.$this->nonce.'.*') ?: [] as $path) {
            if (is_link($path) || ! is_file($path) || ! unlink($path)) {
                throw new RuntimeException('Cannot safely clean transaction journal temporary '.$path);
            }
        }
    }

    /** @phpstan-assert-if-true ?string $value */
    private static function bytes(mixed $value): bool
    {
        return $value === null || (is_string($value) && base64_decode($value, true) !== false);
    }
}
