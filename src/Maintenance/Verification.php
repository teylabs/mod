<?php

namespace Tey\Mod\Maintenance;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Runs trusted host checks under a live file claim; records no installed version. @internal */
final class Verification
{
    /**
     * Callbacks are trusted host code, never interpreted from recipe or journal data.
     * Results are ephemeral: callers must not reuse them after releasing the claim.
     *
     * @param  array<string, Closure>  $registry
     * @return array{passed: bool, plan: string, checks: array<string, string>}
     */
    public static function run(Journal $journal, array $registry): array
    {
        $plan = $journal->plan();
        $registry = self::registry($registry, $plan->target->checks);
        self::target($journal);
        $outcomes = [];
        foreach ($plan->target->checks as $id) {
            self::target($journal);
            if (! isset($registry[$id])) {
                $outcomes[$id] = 'missing';
            } else {
                try {
                    $result = $registry[$id]();
                    $outcomes[$id] = match ($result) {
                        true => 'passed',
                        false => 'failed',
                        default => 'invalid',
                    };
                } catch (Throwable) {
                    $outcomes[$id] = 'error';
                }
            }
            // Even a failing or throwing verifier may have changed files or host inputs.
            self::target($journal);
        }
        self::target($journal);

        return ['passed' => count(array_filter($outcomes, fn (string $outcome): bool => $outcome !== 'passed')) === 0, 'plan' => $plan->identity(), 'checks' => $outcomes];
    }

    /**
     * @param  array<array-key, mixed>  $registry
     * @param  list<string>  $declared
     * @return array<string, Closure>
     */
    private static function registry(array $registry, array $declared): array
    {
        $valid = [];
        foreach ($registry as $id => $callback) {
            if (! is_string($id) || ! in_array($id, $declared, true) || ! $callback instanceof Closure) {
                throw new InvalidArgumentException('Checks require declared identities and trusted host closures.');
            }
            $valid[$id] = $callback;
        }

        return $valid;
    }

    private static function target(Journal $journal): void
    {
        $plan = $journal->plan();
        foreach ($plan->target->files as $path => $bytes) {
            if (SafeFilesystem::read($plan->root, $path) !== $bytes) {
                throw new RuntimeException('Verification requires every generated file to match the target.');
            }
        }
    }
}
