<?php

namespace Tey\Mod\Maintenance;

use Generator;
use RuntimeException;
use Throwable;

/** Scoped data-only preparation; not a public repair or ownership API. @internal */
final class Journal
{
    /** @var resource|null */
    private $lock;

    /**
     * @param  resource  $lock
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $inputPaths
     * @param  array<string, string>  $completedFiles
     */
    private function __construct($lock, private array $record, private FilePlan $plan, private array $inputPaths, private string $relative, private string $recordHash, private array $completedFiles)
    {
        $this->lock = $lock;
    }

    /** @param array<string, string> $inputPaths */
    public static function prepare(FilePlan $plan, array $inputPaths, string $intent, bool $exclusiveCheckout, bool $recovery = false): self
    {
        if (! $exclusiveCheckout) {
            throw new RuntimeException('Repair preparation requires an exclusively owned checkout.');
        }
        if ($intent === '' || strlen($intent) > 120 || preg_match('/[\x00-\x1F\x7F]/', $intent)) {
            throw new RuntimeException('A stable, size-limited intent identity is required.');
        }
        self::fresh($plan, $inputPaths, $recovery);
        if ($recovery && ! is_file(SafeFilesystem::path($plan->root, '.mod-maintenance/journal-'.hash('sha256', $intent).'.json'))) {
            throw new RuntimeException('Recovery requires an existing prepared journal.');
        }
        self::initialize($plan);
        $lockPath = SafeFilesystem::path($plan->root, '.mod-maintenance/lock');
        if (file_exists($lockPath)) {
            self::regular($lockPath);
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open maintenance claim.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Maintenance checkout is already claimed.');
            }
            self::fresh($plan, $inputPaths, $recovery);
            self::owner($plan);
            $record = ['format' => 2, 'state' => 'prepared', 'envelope' => [
                'app_id' => $plan->appId,
                'root' => $plan->root,
                'lineage' => $plan->target->name,
                'intent' => $intent,
                'plan' => $plan->identity(),
                'baseline' => $plan->baseline->identity(),
                'target' => $plan->target->identity(),
                'files' => $plan->files,
                'originals' => array_map(base64_encode(...), $plan->baseline->files),
                'targets' => array_map(base64_encode(...), $plan->target->files),
                'inputs' => $plan->inputs,
                'compatibility' => $plan->compatibility,
                'required_checks' => $plan->target->checks,
            ], 'completed_files' => []];
            if ($plan->claim !== []) {
                // Claimed bytes are recorded as the original, so recovery and verification work unchanged.
                $claimed = array_filter($plan->files, static fn (array $entry): bool => $entry['state'] === 'claim');
                $record['envelope']['claimed'] = array_map(base64_encode(...), array_intersect_key($plan->target->files, $claimed));
            }
            $relative = '.mod-maintenance/journal-'.hash('sha256', $intent).'.json';
            $path = SafeFilesystem::path($plan->root, $relative);
            if (file_exists($path)) {
                $existing = self::readData($path);
                if (($existing['format'] ?? null) !== 2) {
                    throw new RuntimeException('Unsupported prepared journal format.');
                }
                $completed = self::completed($existing, $plan);
                $record['completed_files'] = $completed;
                if (is_array($existing['envelope'] ?? null) && ! array_key_exists('lineage', $existing['envelope'])) {
                    // A journal prepared by 0.8 names no lineage; keep its exact shape.
                    unset($record['envelope']['lineage']);
                }
                if (Fingerprint::of($existing) !== Fingerprint::of($record)) {
                    throw new RuntimeException('Intent collision or invalid prepared journal.');
                }
            } else {
                self::atomicData($plan->root, $relative, $record);
            }

            return new self($lock, $record, $plan, $inputPaths, $relative, self::fileHash($path), self::completed($record, $plan));
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
    }

    /** @param array<string, string> $inputPaths */
    private static function fresh(FilePlan $plan, array $inputPaths, bool $recovery = false): void
    {
        $fresh = Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $plan->claim);
        // Another lineage may have taken ownership since the original plan was made.
        $refused = array_filter($fresh->files, static fn (array $entry): bool => isset($entry['reason']));
        if (! $plan->executable() || $refused !== [] || Fingerprint::of($fresh->inputs) !== Fingerprint::of($plan->inputs) || (! $recovery && $fresh->identity() !== $plan->identity())) {
            throw new RuntimeException('Conflicted, incompatible or stale maintenance plan.');
        }
        if ($recovery) {
            foreach ($plan->files as $path => $entry) {
                $current = $fresh->files[$path]['current'];
                if ($current !== $entry['current'] && $current !== $entry['target']) {
                    throw new RuntimeException('Third file value blocks maintenance recovery.');
                }
            }
        }
    }

    /**
     * The first other lineage with an unfinished journal: its installed state is still that journal's baseline.
     * Reads maintenance metadata only. A 0.8 journal names no lineage and belongs to the 0.8 record.
     */
    public static function pending(string $root, Artifact $target): ?string
    {
        foreach (self::unfinished($root, $target) as $journal) {
            if ($journal['name'] !== $target->name) {
                return $journal['name'];
            }
        }

        return null;
    }

    /**
     * Every unfinished journal, lazily in directory order, except the given target's own.
     * An unattributable 0.8 journal is named 'unknown'.
     *
     * @return Generator<int, array{name: string, intent: mixed, target: string}>
     */
    public static function unfinished(string $root, ?Artifact $target = null): Generator
    {
        $directory = SafeFilesystem::path($root, '.mod-maintenance');
        if (! is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to inspect maintenance journals.');
        }
        foreach ($entries as $entry) {
            if (! preg_match('/\Ajournal-[a-f0-9]{64}\.json\z/', $entry)) {
                continue;
            }
            $envelope = self::readData($directory.'/'.$entry)['envelope'] ?? null;
            if (! is_array($envelope) || ! is_string($envelope['plan'] ?? null) || ! is_string($envelope['baseline'] ?? null) || ! is_string($envelope['target'] ?? null) || ! is_array($envelope['originals'] ?? null)) {
                throw new RuntimeException('Invalid prepared journal schema.');
            }
            $lineage = $envelope['lineage'] ?? ($target !== null && $envelope['target'] === $target->identity() ? $target->name : self::legacyLineage($root));
            if ($target !== null && $lineage === $target->name) {
                continue;
            }
            if (! is_string($lineage)) {
                if (! self::committed($root, $envelope['plan'])) {
                    yield ['name' => 'unknown', 'intent' => $envelope['intent'] ?? null, 'target' => $envelope['target']];
                }

                continue;
            }
            $relative = InstalledRecord::locate($root, $lineage);
            $installed = $relative === null ? null : self::readData(SafeFilesystem::path($root, $relative));
            $artifact = $installed['artifact'] ?? null;
            $commit = $installed['commit'] ?? null;
            $finished = is_array($commit) && ($commit['plan'] ?? null) === $envelope['plan'];
            $unmoved = is_array($artifact) ? ($artifact['identity'] ?? null) === $envelope['baseline'] : $envelope['originals'] === [];
            if (! $finished && $unmoved) {
                yield ['name' => $lineage, 'intent' => $envelope['intent'] ?? null, 'target' => $envelope['target']];
            }
        }
    }

    /** Whether any installed record committed this plan; a migrated 0.8 record no longer names its journal's lineage. */
    private static function committed(string $root, string $plan): bool
    {
        $records = glob(SafeFilesystem::path($root, InstalledRecord::DIRECTORY).'/*.json') ?: [];
        foreach ($records as $record) {
            if (! preg_match('/\A[a-f0-9]{64}\.json\z/', basename($record))) {
                continue;
            }
            $commit = self::readData($record)['commit'] ?? null;
            if (is_array($commit) && ($commit['plan'] ?? null) === $plan) {
                return true;
            }
        }

        return false;
    }

    private static function legacyLineage(string $root): ?string
    {
        $legacy = SafeFilesystem::path($root, InstalledRecord::LEGACY);
        if (! file_exists($legacy) && ! is_link($legacy)) {
            return null;
        }
        $artifact = self::readData($legacy)['artifact'] ?? null;
        $name = is_array($artifact) ? ($artifact['name'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    private static function initialize(FilePlan $plan): void
    {
        $destination = SafeFilesystem::path($plan->root, '.mod-maintenance');
        if (file_exists($destination)) {
            self::owner($plan);

            return;
        }
        // Assemble ownership off to the side; never adopt an existing unmarked directory.
        $relative = '.mod-maintenance-stage-'.bin2hex(random_bytes(12));
        $stage = SafeFilesystem::path($plan->root, $relative);
        if (! mkdir($stage, 0700)) {
            throw new RuntimeException('Unable to prepare maintenance ownership.');
        }
        try {
            self::atomicData($plan->root, $relative.'/owner.json', ['format' => 1, 'owner' => 'tey/mod:maintenance', 'app_id' => $plan->appId, 'root' => $plan->root]);
            if (file_exists($destination) || is_link($destination) || ! rename($stage, $destination)) {
                throw new RuntimeException('Maintenance ownership changed during preparation.');
            }
        } finally {
            // Only these exact files were created here; never walk or remove a host subtree.
            if (is_dir($stage) && ! is_link($stage)) {
                $owner = $stage.'/owner.json';
                if (is_file($owner) && ! is_link($owner)) {
                    unlink($owner);
                }
                if (scandir($stage) === ['.', '..']) {
                    rmdir($stage);
                }
            }
        }
        self::owner($plan);
    }

    private static function owner(FilePlan $plan): void
    {
        SafeFilesystem::metadata($plan->root);
        $path = SafeFilesystem::path($plan->root, '.mod-maintenance/owner.json');
        $expected = ['format' => 1, 'owner' => 'tey/mod:maintenance', 'app_id' => $plan->appId, 'root' => $plan->root];
        if (Fingerprint::of(self::readData($path)) !== Fingerprint::of($expected)) {
            throw new RuntimeException('Unknown or foreign maintenance ownership.');
        }
    }

    /** @return array<string, mixed> */
    public static function readData(string $path): array
    {
        self::regular($path);
        $length = filesize($path);
        if ($length === false || $length > 16 * 1024 * 1024) {
            throw new RuntimeException('Maintenance data exceeds the supported envelope.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read maintenance data.');
        }
        $value = json_decode($raw, true);
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Invalid maintenance data schema.');
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('Maintenance object keys must be strings.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    private static function regular(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Maintenance data requires an unlinked regular file.');
        }
        $stat = lstat($path);
        if ($stat === false || $stat['nlink'] !== 1) {
            throw new RuntimeException('Maintenance data requires an unlinked regular file.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function atomicData(string $root, string $relative, array $value, ?string $expectedHash = null): void
    {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($bytes) > 16 * 1024 * 1024) {
            throw new RuntimeException('Maintenance data exceeds the supported envelope.');
        }
        $path = SafeFilesystem::path($root, $relative);
        $temporary = SafeFilesystem::path($root, $relative.'.tmp-'.bin2hex(random_bytes(12)));
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create maintenance data.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write complete maintenance data.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Unable to flush maintenance data durably.');
            }
            fclose($stream);
            SafeFilesystem::path($root, $relative);
            $matches = $expectedHash === null ? ! file_exists($path) && ! is_link($path) : self::fileHash($path) === $expectedHash;
            if (! $matches || ! rename($temporary, $path)) {
                throw new RuntimeException('Maintenance destination changed during preparation.');
            }
            if (self::readData($path) !== $value) {
                throw new RuntimeException('Maintenance data readback failed.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Reconstruct the original plan only from trusted artifacts and validated expected hashes.
     *
     * @param  array<string, string>  $inputPaths
     * @param  array<string, string>  $compatibility
     * @param  list<string>  $claim
     */
    public static function reopen(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, string $intent, bool $exclusiveCheckout, array $claim = []): self
    {
        if (! $exclusiveCheckout || $intent === '' || strlen($intent) > 120 || preg_match('/[\x00-\x1F\x7F]/', $intent)) {
            throw new RuntimeException('Exclusive checkout and stable intent are required.');
        }
        $fresh = Checkout::inspect($root, $appId, $baseline, $target, $inputPaths, $compatibility, $claim);
        $path = SafeFilesystem::path($root, '.mod-maintenance/journal-'.hash('sha256', $intent).'.json');
        $saved = self::readData($path);
        $envelope = $saved['envelope'] ?? null;
        if (! is_array($envelope) || ! is_array($envelope['files'] ?? null)) {
            throw new RuntimeException('Invalid prepared recovery schema.');
        }
        $original = [];
        foreach ($target->files as $relative => $bytes) {
            $entry = $envelope['files'][$relative] ?? null;
            if (! is_array($entry) || ! array_key_exists('current', $entry)) {
                throw new RuntimeException('Missing prepared file identity.');
            }
            if ($entry['current'] !== null) {
                // A claimed original is the target's own bytes: only a trusted artifact can supply them.
                $trusted = $baseline->files[$relative] ?? (in_array($relative, $claim, true) ? $bytes : null);
                if ($trusted === null || $entry['current'] !== hash('sha256', $trusted)) {
                    throw new RuntimeException('Untrusted original recovery bytes.');
                }
                $original[$relative] = $trusted;
            }
        }
        $plan = FilePlan::between($appId, $root, $baseline, $target, $original, $fresh->inputs, $compatibility, claim: $claim);

        return self::prepare($plan, $inputPaths, $intent, $exclusiveCheckout, recovery: true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private static function completed(array $record, FilePlan $plan): array
    {
        $steps = $record['completed_files'] ?? null;
        if (! is_array($steps)) {
            throw new RuntimeException('Invalid file receipt schema.');
        }
        $valid = [];
        foreach ($steps as $path => $hash) {
            if (! is_string($path) || ! isset($plan->target->files[$path]) || $hash !== hash('sha256', $plan->target->files[$path])) {
                throw new RuntimeException('Invalid completed file identity.');
            }
            $valid[$path] = $hash;
        }

        return $valid;
    }

    private static function fileHash(string $path): string
    {
        self::regular($path);
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Unable to fingerprint maintenance data.');
        }

        return $hash;
    }

    public function assertCurrent(): void
    {
        if (! is_resource($this->lock)) {
            throw new RuntimeException('Maintenance claim is closed.');
        }
        self::fresh($this->plan, $this->inputPaths, recovery: true);
        self::owner($this->plan);
        if (self::fileHash(SafeFilesystem::path($this->plan->root, $this->relative)) !== $this->recordHash) {
            throw new RuntimeException('Prepared journal changed during execution.');
        }
    }

    public function plan(): FilePlan
    {
        $this->assertCurrent();

        return $this->plan;
    }

    public function completeFile(string $path): void
    {
        $this->assertCurrent();
        if (! isset($this->plan->target->files[$path]) || SafeFilesystem::read($this->plan->root, $path) !== $this->plan->target->files[$path]) {
            throw new RuntimeException('Generated file readback does not match target.');
        }
        if (isset($this->completedFiles[$path])) {
            return;
        }
        $next = $this->record;
        $completed = $this->completedFiles;
        $completed[$path] = hash('sha256', $this->plan->target->files[$path]);
        $next['completed_files'] = $completed;
        self::atomicData($this->plan->root, $this->relative, $next, $this->recordHash);
        $this->record = $next;
        $this->completedFiles = $completed;
        $this->recordHash = self::fileHash(SafeFilesystem::path($this->plan->root, $this->relative));
    }

    public function assertVerifierInputs(): void
    {
        $this->assertCurrent();
        foreach (['verification-policy', 'verification-code'] as $id) {
            if (! isset($this->inputPaths[$id]) || SafeFilesystem::read($this->plan->root, $this->inputPaths[$id]) === null) {
                throw new RuntimeException('Recording requires existing named verification policy and code inputs.');
            }
        }
    }

    public function intent(): string
    {
        $this->assertCurrent();
        $envelope = $this->record['envelope'] ?? null;
        if (! is_array($envelope) || ! is_string($envelope['intent'] ?? null)) {
            throw new RuntimeException('Missing prepared intent.');
        }

        return $envelope['intent'];
    }

    /** @return array<string, mixed>|null */
    public function installed(): ?array
    {
        $this->assertCurrent();
        $relative = InstalledRecord::locate($this->plan->root, $this->plan->target->name);

        return $relative === null ? null : self::readData(SafeFilesystem::path($this->plan->root, $relative));
    }

    /**
     * Commit the lineage record last. A 0.8 record of the same name migrates on this write.
     *
     * @param  array<string, mixed>  $record
     */
    public function recordInstalled(array $record, ?string $expectedHash): void
    {
        $this->assertVerifierInputs();
        foreach ($this->plan->target->files as $path => $bytes) {
            if (SafeFilesystem::read($this->plan->root, $path) !== $bytes) {
                throw new RuntimeException('Installed recording requires exact target bytes.');
            }
        }
        $root = $this->plan->root;
        $name = $this->plan->target->name;
        $located = InstalledRecord::locate($root, $name);
        self::directory($root, InstalledRecord::DIRECTORY);
        if ($located === InstalledRecord::LEGACY) {
            if (self::fileHash(SafeFilesystem::path($root, InstalledRecord::LEGACY)) !== $expectedHash) {
                throw new RuntimeException('Maintenance destination changed during preparation.');
            }
            self::atomicData($root, InstalledRecord::relative($name), $record);
        } else {
            self::atomicData($root, InstalledRecord::relative($name), $record, $expectedHash);
        }
        self::retireLegacy($root, $name);
    }

    /** The lineage record is authoritative once written; a 0.8 record of the same name is superseded. */
    private static function retireLegacy(string $root, string $name): void
    {
        $legacy = SafeFilesystem::path($root, InstalledRecord::LEGACY);
        if (! file_exists($legacy) || is_link($legacy)) {
            return;
        }
        try {
            $artifact = self::readData($legacy)['artifact'] ?? null;
        } catch (RuntimeException) {
            // The commit already stands; unreadable leftovers are refused by the next inspection.
            return;
        }
        if (is_array($artifact) && ($artifact['name'] ?? null) === $name) {
            unlink($legacy);
        }
    }

    private static function directory(string $root, string $relative): void
    {
        $path = SafeFilesystem::path($root, $relative);
        if (! file_exists($path) && ! is_link($path) && ! mkdir($path, 0700)) {
            throw new RuntimeException('Unable to prepare installed lineage records.');
        }
        if (! is_dir($path) || is_link($path)) {
            throw new RuntimeException('Installed lineage records require an unlinked directory.');
        }
    }

    public function installedHash(): ?string
    {
        $this->assertCurrent();
        $relative = InstalledRecord::locate($this->plan->root, $this->plan->target->name);

        return $relative === null ? null : self::fileHash(SafeFilesystem::path($this->plan->root, $relative));
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return $this->record;
    }

    public function close(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
