<?php

namespace Tey\Mod\Maintenance;

use InvalidArgumentException;
use RuntimeException;

/** Local lifecycle data is validated against trusted artifacts, never treated as a permission grant. @internal */
final class InstalledRecord
{
    public const DIRECTORY = '.mod-maintenance/installed';

    public const DIRECTORY_NAME = 'installed';

    /** A record names its checkout relatively: committed records travel to clones, CI and worktrees, and never leak a local path. */
    public const ROOT = '.';

    /** The single-lineage 0.8 record, read as the record for its own name until that lineage's next write. */
    public const LEGACY = '.mod-maintenance/installed.json';

    public static function relative(string $name): string
    {
        return self::DIRECTORY.'/'.hash('sha256', $name).'.json';
    }

    /** The live record for one lineage: its own record, else a 0.8 record of the same name. */
    public static function locate(string $root, string $name): ?string
    {
        $own = self::relative($name);
        $path = SafeFilesystem::path($root, $own);
        if (file_exists($path) || is_link($path)) {
            return $own;
        }
        $legacy = SafeFilesystem::path($root, self::LEGACY);
        if (! file_exists($legacy) && ! is_link($legacy)) {
            return null;
        }
        $artifact = Journal::readData($legacy)['artifact'] ?? null;

        return is_array($artifact) && ($artifact['name'] ?? null) === $name ? self::LEGACY : null;
    }

    /**
     * Every installed lineage, rebuilt and verified from its record. Reads metadata only.
     * The excepted lineage is skipped unread: its own record is verified against its trusted baseline instead.
     *
     * @return array<string, Artifact>
     */
    public static function lineages(string $root, string $appId, ?string $except = null): array
    {
        SafeFilesystem::root($root);
        SafeFilesystem::metadata($root);
        $lineages = [];
        $directory = SafeFilesystem::path($root, self::DIRECTORY);
        if (is_dir($directory)) {
            $entries = scandir($directory);
            if ($entries === false) {
                throw new RuntimeException('Unable to inspect installed lineage records.');
            }
            foreach ($entries as $entry) {
                // Interrupted temporary writes are left to orphan diagnostics.
                if (! preg_match('/\A[a-f0-9]{64}\.json\z/', $entry) || ($except !== null && $entry === hash('sha256', $except).'.json')) {
                    continue;
                }
                $artifact = self::stored(Journal::readData($directory.'/'.$entry), $root, $appId);
                if ($entry !== hash('sha256', $artifact->name).'.json') {
                    throw new RuntimeException('Installed record is filed under a foreign lineage.');
                }
                $lineages[$artifact->name] = $artifact;
            }
        }
        $legacy = SafeFilesystem::path($root, self::LEGACY);
        if (file_exists($legacy) || is_link($legacy)) {
            $record = Journal::readData($legacy);
            $data = $record['artifact'] ?? null;
            if ($except === null || ! is_array($data) || ($data['name'] ?? null) !== $except) {
                $artifact = self::stored($record, $root, $appId);
                // An interrupted migration leaves a superseded 0.8 copy behind.
                $lineages[$artifact->name] ??= $artifact;
            }
        }
        ksort($lineages);

        return $lineages;
    }

    /** @param array<string, mixed> $record */
    private static function stored(array $record, string $root, string $appId): Artifact
    {
        $data = $record['artifact'] ?? null;
        if (($record['format'] ?? null) !== 1 || ($record['owner'] ?? null) !== 'tey/mod:installed' || ($record['app_id'] ?? null) !== $appId || ! self::root($record['root'] ?? null) || ! is_array($data) || ! is_array($data['files'] ?? null)) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }
        $files = [];
        foreach ($data['files'] as $path => $encoded) {
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($path) || $bytes === false) {
                throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
            }
            $files[$path] = $bytes;
        }
        $name = $data['name'] ?? null;
        $version = $data['version'] ?? null;
        $groups = self::strings($data['groups'] ?? null);
        $checks = self::strings($data['checks'] ?? null);
        $hostOwned = self::strings($data['host_owned'] ?? []);
        if (! is_string($name) || ! is_string($version) || ! array_is_list($groups) || ! array_is_list($checks) || ! array_is_list($hostOwned)) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }
        try {
            $artifact = new Artifact($name, $version, $files, $groups, self::named($data['bindings'] ?? null), $checks, metadata: self::named($data['metadata'] ?? [], strings: false), requires: self::named($data['requires'] ?? []), hostOwned: $hostOwned);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.', previous: $exception);
        }
        if (Fingerprint::of($data) !== Fingerprint::of(self::artifact($artifact))) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }

        return $artifact;
    }

    /**
     * The root is not identity: the app id, the lineage (filed under its own name's hash), the artifact fingerprint and the commit receipt are.
     * Records before 0.11.3 stored the absolute path of the checkout that wrote them; they are read anywhere and rewritten as `.` on the next apply.
     */
    private static function root(mixed $root): bool
    {
        return $root === self::ROOT || (is_string($root) && str_starts_with($root, '/') && ! preg_match('/[\x00-\x1F\x7F]/', $root));
    }

    /** @return array<array-key, string> */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
            }
        }

        return $values;
    }

    /**
     * @return ($strings is true ? array<string, string> : array<string, mixed>)
     */
    private static function named(mixed $values, bool $strings = true): array
    {
        if (! is_array($values)) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }
        $named = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ($strings && ! is_string($value))) {
                throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
            }
            $named[$key] = $value;
        }

        return $named;
    }

    /** @return array<string, mixed> */
    public static function artifact(Artifact $artifact): array
    {
        $record = ['identity' => $artifact->identity(), 'name' => $artifact->name, 'version' => $artifact->version, 'files' => array_map(base64_encode(...), $artifact->files), 'groups' => $artifact->groups, 'bindings' => $artifact->bindings, 'checks' => $artifact->checks];
        if ($artifact->metadata !== []) {
            $record['metadata'] = $artifact->metadata;
        }
        if ($artifact->requires !== []) {
            $record['requires'] = $artifact->requires;
        }
        if ($artifact->hostOwned !== []) {
            $record['host_owned'] = $artifact->hostOwned;
        }

        return $record;
    }

    /** @param array<string, mixed>|null $record */
    public static function baseline(?array $record, FilePlan $plan): void
    {
        if ($record === null) {
            if ($plan->baseline->files !== []) {
                throw new RuntimeException('Upgrade requires a matching installed baseline.');
            }

            return;
        }
        $keys = array_keys($record);
        sort($keys);
        $commit = $record['commit'] ?? null;
        if (! is_array($commit)) {
            throw new RuntimeException('Missing installed commit identity.');
        }
        $expectedCommitKeys = ['checks', 'compatibility', 'inputs', 'intent', 'plan'];
        $commitKeys = array_keys($commit);
        sort($commitKeys);
        if ($keys !== ['app_id', 'artifact', 'commit', 'format', 'owner', 'root'] || ($record['format'] ?? null) !== 1 || ($record['owner'] ?? null) !== 'tey/mod:installed' || ($record['app_id'] ?? null) !== $plan->appId || ! self::root($record['root'] ?? null) || Fingerprint::of($record['artifact'] ?? null) !== Fingerprint::of(self::artifact($plan->baseline)) || $commitKeys !== $expectedCommitKeys) {
            throw new RuntimeException('Unknown, foreign or mismatched installed artifact.');
        }
        if (! is_string($commit['intent']) || $commit['intent'] === '' || strlen($commit['intent']) > 120 || preg_match('/[\x00-\x1F\x7F]/', $commit['intent']) || ! is_string($commit['plan']) || ! preg_match('/\Asha256:[a-f0-9]{64}\z/', $commit['plan']) || ! is_array($commit['inputs']) || ! is_array($commit['compatibility']) || $commit['compatibility'] === [] || ! is_array($commit['checks']) || Fingerprint::of($commit['checks']) !== Fingerprint::of(array_fill_keys($plan->baseline->checks, 'passed'))) {
            throw new RuntimeException('Invalid installed verification receipt.');
        }
        Artifact::validateSelection($commit['inputs'], false);
        Artifact::validateSelection($commit['compatibility'], false);
        foreach ($commit['compatibility'] as $outcome) {
            if ($outcome !== 'passed') {
                throw new RuntimeException('Installed compatibility was not satisfied.');
            }
        }
    }
}
