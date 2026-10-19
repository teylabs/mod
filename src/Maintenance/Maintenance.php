<?php

namespace Tey\Mod\Maintenance;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Experimental POSIX whole-file delivery under an exclusively owned checkout. @api */
final class Maintenance
{
    /**
     * Read-only, size-limited diagnostics; no candidate is deleted, adopted or followed.
     *
     * @param  array<string, string>  $inputPaths
     * @return array{plan: string, current: bool, executable: bool, orphans: list<array{path: string, kind: string}>}
     *
     * @api
     */
    public static function diagnose(FilePlan $plan, array $inputPaths): array
    {
        $orphans = Orphans::inspect($plan->root, $plan->target);
        $fresh = $orphans === [] ? Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility) : null;

        return ['plan' => $plan->identity(), 'current' => $fresh?->identity() === $plan->identity(), 'executable' => $fresh?->executable() ?? false, 'orphans' => $orphans];
    }

    /**
     * Required checks are trusted host closures, never command strings or JSON callbacks.
     * An exact completed retry may omit callbacks: it neither reruns checks nor writes receipts.
     *
     * @param  array<string, string>  $inputPaths
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     *
     * @api
     */
    public static function apply(FilePlan $plan, array $inputPaths, string $intent, array $registry, bool $exclusiveCheckout): array
    {
        self::boundary($intent, $exclusiveCheckout);
        self::registry($registry, $plan->target->checks, false);
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans);
        }
        Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility);
        $selected = SafeFilesystem::path($plan->root, '.mod-maintenance/journal-'.hash('sha256', $intent).'.json');
        if (file_exists($selected)) {
            $claim = Journal::reopen($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $intent, $exclusiveCheckout);
        } else {
            self::registry($registry, $plan->target->checks);
            self::inputs($plan->root, $inputPaths);
            $claim = Journal::prepare($plan, $inputPaths, $intent, $exclusiveCheckout);
        }
        try {
            if ($claim->plan()->identity() !== $plan->identity()) {
                throw new RuntimeException('Saved intent does not match the caller original plan.');
            }

            return self::execute($claim, $registry);
        } finally {
            $claim->close();
        }
    }

    /**
     * Reopen only an existing exact intent with trusted immutable artifacts and current host policy.
     *
     * @param  array<string, string>  $inputPaths
     * @param  array<string, string>  $compatibility
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     *
     * @api
     */
    public static function resume(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, string $intent, array $registry, bool $exclusiveCheckout): array
    {
        self::boundary($intent, $exclusiveCheckout);
        FilePlan::between($appId, $root, $baseline, $target, [], [], $compatibility);
        self::registry($registry, $target->checks, false);
        $orphans = Orphans::inspect($root, $target);
        if ($orphans !== []) {
            return self::blocked(null, $orphans);
        }
        $claim = Journal::reopen($root, $appId, $baseline, $target, $inputPaths, $compatibility, $intent, $exclusiveCheckout);
        try {
            return self::execute($claim, $registry);
        } finally {
            $claim->close();
        }
    }

    private static function boundary(string $intent, bool $exclusiveCheckout): void
    {
        if (! $exclusiveCheckout || $intent === '' || strlen($intent) > 120 || preg_match('/[\x00-\x1F\x7F]/', $intent)) {
            throw new RuntimeException('Exclusive checkout and a stable, size-limited intent are required.');
        }
    }

    /** @param array<array-key, mixed> $registry
     * @param  list<string>  $required
     */
    private static function registry(array $registry, array $required, bool $requireAll = true): void
    {
        foreach ($registry as $id => $callback) {
            if (! is_string($id) || ! in_array($id, $required, true) || ! $callback instanceof Closure) {
                throw new InvalidArgumentException('Only declared trusted check closures are accepted.');
            }
        }
        if ($requireAll && array_diff($required, array_keys($registry)) !== []) {
            throw new InvalidArgumentException('Every required check must be registered before file effects.');
        }
    }

    /** @param array<string, string> $inputPaths */
    private static function inputs(string $root, array $inputPaths): void
    {
        foreach (['verification-policy', 'verification-code'] as $id) {
            if (! isset($inputPaths[$id]) || SafeFilesystem::read($root, $inputPaths[$id]) === null) {
                throw new RuntimeException('Existing verification policy and code inputs are required.');
            }
        }
    }

    /**
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     */
    private static function execute(Journal $claim, array $registry): array
    {
        $plan = $claim->plan();
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans);
        }
        $files = ['written' => [], 'reconciled' => []];
        if (($complete = Finalization::completed($claim)) !== null) {
            return $complete + $files + ['plan' => $plan->identity(), 'orphans' => []];
        }
        self::registry($registry, $plan->target->checks);
        $claim->assertVerifierInputs();
        InstalledRecord::baseline($claim->installed(), $plan);
        $files = FileRepair::apply($claim);
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans, $files);
        }
        try {
            $result = Finalization::record($claim, $registry, beforeRecord: function () use ($plan, &$orphans): void {
                $orphans = Orphans::inspect($plan->root, $plan->target);
                if ($orphans !== []) {
                    throw new RuntimeException('Unresolved maintenance candidates block completion.');
                }
            });
        } catch (RuntimeException $exception) {
            if ($orphans === []) {
                throw $exception;
            }

            return self::blocked($plan->identity(), $orphans, $files);
        }
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans, $files);
        }

        return $result + $files + ['plan' => $plan->identity(), 'orphans' => []];
    }

    /**
     * @param  list<array{path: string, kind: string}>  $orphans
     * @param  array{written: list<string>, reconciled: list<string>}  $files
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     */
    private static function blocked(?string $plan, array $orphans, array $files = ['written' => [], 'reconciled' => []]): array
    {
        return ['status' => 'orphaned', 'no_op' => false, 'plan' => $plan, 'receipt' => null, 'checks' => [], 'orphans' => $orphans] + $files;
    }
}
