<?php

namespace Tey\Mod\Maintenance;

use Closure;
use RuntimeException;

/** Commit the installed file artifact only after fresh checks under the same live claim. @internal */
final class Finalization
{
    /**
     * Hooks are trusted in-process interruption probes, never journal-supplied callbacks.
     *
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, receipt: ?string, checks: array<string, string>}
     */
    public static function record(Journal $journal, array $registry, ?Closure $beforeRecord = null, ?Closure $afterRecord = null): array
    {
        $journal->assertVerifierInputs();
        $plan = $journal->plan();
        $checks = array_fill_keys($plan->target->checks, 'passed');
        $record = ['format' => 1, 'owner' => 'tey/mod:installed', 'app_id' => $plan->appId, 'root' => $plan->root, 'artifact' => InstalledRecord::artifact($plan->target), 'commit' => ['intent' => $journal->intent(), 'plan' => $plan->identity(), 'inputs' => $plan->inputs, 'compatibility' => $plan->compatibility, 'checks' => $checks]];
        $originalHash = $journal->installedHash();
        $current = $journal->installed();
        if ($journal->installedHash() !== $originalHash) {
            throw new RuntimeException('Installed record changed during inspection.');
        }
        if ($current !== null && Fingerprint::of($current) === Fingerprint::of($record)) {
            // Do not re-run checks or effects for a fully matching completed receipt.
            foreach ($plan->target->files as $path => $bytes) {
                if (SafeFilesystem::read($plan->root, $path) !== $bytes) {
                    throw new RuntimeException('Completed installed files no longer match the target.');
                }
            }

            return ['status' => 'completed', 'no_op' => true, 'receipt' => $originalHash, 'checks' => $checks];
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
