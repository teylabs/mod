<?php

namespace Tey\Mod\Maintenance;

use Closure;
use RuntimeException;

/** Commit the installed file artifact only after fresh checks under the same live claim. @internal */
final class Finalization
{
    /** @return array{status: string, no_op: bool, receipt: ?string, checks: array<string, string>}|null */
    public static function completed(Journal $journal): ?array
    {
        $journal->assertVerifierInputs();
        $plan = $journal->plan();
        $expected = self::expected($journal);
        $hash = $journal->installedHash();
        $current = $journal->installed();
        if ($journal->installedHash() !== $hash) {
            throw new RuntimeException('Installed record changed during inspection.');
        }
        if ($current === null || Fingerprint::of($current) !== Fingerprint::of($expected)) {
            return null;
        }
        foreach ($plan->target->files as $path => $bytes) {
            if (SafeFilesystem::read($plan->root, $path) !== $bytes) {
                throw new RuntimeException('Completed installed files no longer match the target.');
            }
        }
        $journal->assertCurrent();

        return ['status' => 'completed', 'no_op' => true, 'receipt' => $hash, 'checks' => array_fill_keys($plan->target->checks, 'passed')];
    }

    /** @return array<string, mixed> */
    private static function expected(Journal $journal): array
    {
        $plan = $journal->plan();

        return ['format' => 1, 'owner' => 'tey/mod:installed', 'app_id' => $plan->appId, 'root' => InstalledRecord::ROOT, 'artifact' => InstalledRecord::artifact($plan->target), 'commit' => ['intent' => $journal->intent(), 'plan' => $plan->identity(), 'inputs' => $plan->inputs, 'compatibility' => $plan->compatibility, 'checks' => array_fill_keys($plan->target->checks, 'passed')]];
    }

    /**
     * Hooks are trusted in-process interruption probes, never journal-supplied callbacks.
     *
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, receipt: ?string, checks: array<string, string>}
     */
    public static function record(Journal $journal, array $registry, ?Closure $beforeRecord = null, ?Closure $afterRecord = null): array
    {
        if (($completed = self::completed($journal)) !== null) {
            return $completed;
        }
        $plan = $journal->plan();
        $checks = array_fill_keys($plan->target->checks, 'passed');
        $record = self::expected($journal);
        $originalHash = $journal->installedHash();
        $current = $journal->installed();
        if ($journal->installedHash() !== $originalHash) {
            throw new RuntimeException('Installed record changed during inspection.');
        }
        InstalledRecord::baseline($current, $plan);
        $verification = Verification::run($journal, $registry);
        if (! $verification['passed']) {
            return ['status' => 'verification_failed', 'no_op' => false, 'receipt' => $originalHash, 'checks' => $verification['checks']];
        }
        if ($beforeRecord !== null) {
            $beforeRecord();
        }
        $journal->recordInstalled($record, $originalHash);
        if ($afterRecord !== null) {
            $afterRecord();
        }

        return ['status' => 'completed', 'no_op' => false, 'receipt' => $journal->installedHash(), 'checks' => $checks];
    }
}
