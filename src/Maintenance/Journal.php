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
            if ($plan->removals !== []) {
                // Removed bytes stay in originals, so a failed update can put them back.
                $record['envelope']['removals'] = $plan->removals;
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
                // A progress log without its journal belongs to a journal already cleared: it can describe nothing that is prepared now.
                self::discardProgress($plan->root, $relative);
                self::atomicData($plan->root, $relative, $record);
            }

            return new self($lock, $record, $plan, $inputPaths, $relative, self::fileHash($path), self::completed($record, $plan) + self::progress($plan, $relative));
        } catch (Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $exception;
        }
    }

    /** @param array<string, string> $inputPaths */
    private static function fresh(FilePlan $plan, array $inputPaths, bool $recovery = false): void
    {
        $fresh = Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $plan->claim, $plan->removals);
        // Another lineage may have taken ownership since the original plan was made.
        $refused = array_filter($fresh->files, static fn (array $entry): bool => isset($entry['reason']));
        if (! $plan->executable() || $refused !== [] || Fingerprint::of($fresh->inputs) !== Fingerprint::of($plan->inputs) || (! $recovery && $fresh->identity() !== $plan->identity())) {
            throw new RuntimeException('Conflicted, incompatible or stale maintenance plan.');
        }
        if ($recovery) {
            // A committed release leaves the path to its next owner, so its bytes are no longer the giver's to check.
            $owned = null;
            foreach ($plan->files as $path => $entry) {
                if ($entry['state'] === 'release' && ! isset(($owned ??= Checkout::artifact($plan->root, $plan->appId, $plan->target->name)->files ?? $plan->baseline->files)[$path])) {
                    continue;
                }
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
            // A fresh clone or CI checkout has the committed installed records but not the git-ignored local ownership: claim it in place.
            $owner = SafeFilesystem::path($plan->root, '.mod-maintenance/owner.json');
            if (! file_exists($owner) && ! is_link($owner) && self::committedOnly($plan->root)) {
                self::atomicData($plan->root, '.mod-maintenance/owner.json', ['format' => 1, 'owner' => 'tey/mod:maintenance', 'app_id' => $plan->appId, 'root' => $plan->root]);
            }
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

    /** Whether the maintenance directory holds committed installed records and nothing but what a repository commits: those, a 0.8 record, and git placeholders. */
    private static function committedOnly(string $root): bool
    {
        SafeFilesystem::metadata($root);
        $directory = SafeFilesystem::path($root, '.mod-maintenance');
        $records = 0;
        foreach (array_diff(scandir($directory) ?: ['?'], ['.', '..']) as $name) {
            $path = $directory.'/'.$name;
            if ($name === InstalledRecord::DIRECTORY_NAME && is_dir($path) && ! is_link($path)) {
                foreach (array_diff(scandir($path) ?: ['?'], ['.', '..']) as $record) {
                    if (! preg_match('/\A[a-f0-9]{64}\.json\z/', $record) || ! is_file($path.'/'.$record) || is_link($path.'/'.$record)) {
                        return false;
                    }
                    $records++;
                }

                continue;
            }
            if (! in_array($name, ['installed.json', '.gitignore', '.gitkeep'], true) || ! is_file($path) || is_link($path)) {
                return false;
            }
            $records += $name === 'installed.json' ? 1 : 0;
        }

        // An empty or placeholder-only directory isn't committed lifecycle data; it stays an unmarked directory, never adopted.
        return $records > 0;
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
     * @param  array<string, string>  $removals
     */
    public static function reopen(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, string $intent, bool $exclusiveCheckout, array $claim = [], array $removals = []): self
    {
        if (! $exclusiveCheckout || $intent === '' || strlen($intent) > 120 || preg_match('/[\x00-\x1F\x7F]/', $intent)) {
            throw new RuntimeException('Exclusive checkout and stable intent are required.');
        }
        $fresh = Checkout::inspect($root, $appId, $baseline, $target, $inputPaths, $compatibility, $claim, $removals);
        $path = SafeFilesystem::path($root, '.mod-maintenance/journal-'.hash('sha256', $intent).'.json');
        $saved = self::readData($path);
        $envelope = $saved['envelope'] ?? null;
        if (! is_array($envelope) || ! is_array($envelope['files'] ?? null)) {
            throw new RuntimeException('Invalid prepared recovery schema.');
        }
        if (is_string($envelope['baseline'] ?? null) && is_string($envelope['target'] ?? null) && [$envelope['baseline'], $envelope['target']] !== [$baseline->identity(), $target->identity()]) {
            // A reused intent, not a damaged journal: say what to do before any per-file check.
            throw new RuntimeException("Intent [{$intent}] already has a journal for a different plan. Finish or resume it, or use a new intent.");
        }
        $original = [];
        foreach ($target->files + array_intersect_key($baseline->files, $removals) as $relative => $bytes) {
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
        $plan = FilePlan::between($appId, $root, $baseline, $target, $original, $fresh->inputs, $compatibility, claim: $claim, removals: $removals);

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

    /**
     * The claim's cheap per-step proof, for the steps between full freshness checks: the exclusive lock is still held, the checkout's owner is unchanged and
     * the prepared journal is byte-identical. The full checkout inspection (fresh) runs before the file loop and again before finalization; each file is also
     * checked against its planned bytes right before it is written.
     */
    public function assertClaim(): void
    {
        if (! is_resource($this->lock)) {
            throw new RuntimeException('Maintenance claim is closed.');
        }
        self::owner($this->plan);
        if (self::fileHash(SafeFilesystem::path($this->plan->root, $this->relative)) !== $this->recordHash) {
            throw new RuntimeException('Prepared journal changed during execution.');
        }
    }

    /** The plan under the cheap claim check, for use inside the file loop. */
    public function claimedPlan(): FilePlan
    {
        $this->assertClaim();

        return $this->plan;
    }

    private static function progressRelative(string $relative): string
    {
        return substr($relative, 0, -strlen('.json')).'.progress';
    }

    /**
     * Completed files recorded in the append-only progress log beside the journal: one JSON line per file, each validated as a journal receipt is. A torn final
     * line (a crash while appending) is ignored: that file was not recorded as complete, so it is checked and completed again on resume.
     *
     * @return array<string, string>
     */
    private static function progress(FilePlan $plan, string $relative): array
    {
        $path = SafeFilesystem::path($plan->root, self::progressRelative($relative));
        if (! file_exists($path)) {
            return [];
        }
        self::regular($path);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read maintenance progress.');
        }
        $lines = explode("\n", $contents);
        array_pop($lines);
        $steps = [];
        foreach ($lines as $line) {
            $step = json_decode($line, true, 4);
            if (! is_array($step) || ! is_string($step['path'] ?? null) || ! is_string($step['sha256'] ?? null)) {
                throw new RuntimeException('Invalid maintenance progress.');
            }
            $steps[$step['path']] = $step['sha256'];
        }

        return self::completed(['completed_files' => $steps], $plan);
    }

    private static function discardProgress(string $root, string $relative): void
    {
        $path = SafeFilesystem::path($root, self::progressRelative($relative));
        if (file_exists($path)) {
            self::regular($path);
            if (! unlink($path)) {
                throw new RuntimeException('Unable to clear maintenance progress.');
            }
        }
    }

    public function completeFile(string $path): void
    {
        $this->assertClaim();
        if (! isset($this->plan->target->files[$path]) || SafeFilesystem::read($this->plan->root, $path) !== $this->plan->target->files[$path]) {
            throw new RuntimeException('Generated file readback does not match target.');
        }
        if (isset($this->completedFiles[$path])) {
            return;
        }
        // Appended and fsynced before the next file is written, so a crash resumes exactly: the journal itself is never rewritten per file.
        $hash = hash('sha256', $this->plan->target->files[$path]);
        $progress = SafeFilesystem::path($this->plan->root, self::progressRelative($this->relative));
        if (file_exists($progress)) {
            self::regular($progress);
        }
        $stream = fopen($progress, 'ab');
        if ($stream === false) {
            throw new RuntimeException('Unable to open maintenance progress.');
        }
        try {
            $line = json_encode(['path' => $path, 'sha256' => $hash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Unable to record maintenance progress.');
            }
        } finally {
            fclose($stream);
        }
        $this->completedFiles[$path] = $hash;
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
        foreach ($this->plan->files as $path => $entry) {
            if ($entry['state'] === 'remove' && SafeFilesystem::read($this->plan->root, $path) !== null) {
                throw new RuntimeException('Installed recording requires removed files to stay absent.');
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
        // Recorded: the prepared journal stays (cleared later with stale journals), and its progress log has nothing left to resume.
        self::discardProgress($root, $this->relative);
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
        // Progress lives in the append-only log until the lineage is recorded; the receipt reports it as the journal's own completed files.
        return ['completed_files' => $this->completedFiles] + $this->record;
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
