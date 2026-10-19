<?php

namespace Tey\Mod\Maintenance;

use RuntimeException;

/** Local lifecycle data is validated against trusted artifacts, never treated as a permission grant. @internal */
final class InstalledRecord
{
    /** @return array<string, mixed> */
    public static function artifact(Artifact $artifact): array
    {
        $record = ['identity' => $artifact->identity(), 'name' => $artifact->name, 'version' => $artifact->version, 'files' => array_map(base64_encode(...), $artifact->files), 'groups' => $artifact->groups, 'bindings' => $artifact->bindings, 'checks' => $artifact->checks];
        if ($artifact->metadata !== []) {
            $record['metadata'] = $artifact->metadata;
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
        if ($keys !== ['app_id', 'artifact', 'commit', 'format', 'owner', 'root'] || ($record['format'] ?? null) !== 1 || ($record['owner'] ?? null) !== 'tey/mod:installed' || ($record['app_id'] ?? null) !== $plan->appId || ($record['root'] ?? null) !== $plan->root || Fingerprint::of($record['artifact'] ?? null) !== Fingerprint::of(self::artifact($plan->baseline)) || $commitKeys !== $expectedCommitKeys) {
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
