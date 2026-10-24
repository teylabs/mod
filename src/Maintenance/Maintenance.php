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
     * Claimable paths are unowned paths whose bytes already equal the target: candidates for a reviewed claim list.
     * Removals lists every dropped baseline path with its state; a refused edited file carries its diff and the manual step.
     *
     * @param  array<string, string>  $inputPaths
     * @return array{plan: string, current: bool, executable: bool, orphans: list<array{path: string, kind: string}>, claimable: list<string>, removals: list<array{path: string, state: string, diff?: string, step?: string}>}
     *
     * @api
     */
    public static function diagnose(FilePlan $plan, array $inputPaths): array
    {
        $orphans = Orphans::inspect($plan->root, $plan->target);
        $fresh = $orphans === [] ? Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $plan->claim, $plan->removals) : null;
        $claimable = array_keys(array_filter($fresh->files ?? [], static fn (array $entry): bool => $entry['state'] === 'unowned' && $entry['current'] === $entry['target']));

        return ['plan' => $plan->identity(), 'current' => $fresh?->identity() === $plan->identity(), 'executable' => $fresh?->executable() ?? false, 'orphans' => $orphans, 'claimable' => $claimable, 'removals' => self::removals($fresh ?? $plan)];
    }

    /** @return list<array{path: string, state: string, diff?: string, step?: string}> */
    private static function removals(FilePlan $plan): array
    {
        $removals = [];
        foreach ($plan->removals as $path => $policy) {
            $entry = ['path' => $path, 'state' => $plan->files[$path]['state']];
            if ($entry['state'] === 'conflict') {
                $entry['diff'] = self::diff($plan->baseline->files[$path], SafeFilesystem::read($plan->root, $path) ?? '');
                $entry['step'] = 'Edit the file back to the baseline, delete it by hand, or switch its policy to release.';
            }
            $removals[] = $entry;
        }

        return $removals;
    }

    /** A minimal line diff: the shared head and tail are kept as context, the changed middle is shown removed then added. */
    private static function diff(string $baseline, string $current): string
    {
        $old = explode("\n", $baseline);
        $new = explode("\n", $current);
        $head = 0;
        while ($head < count($old) && $head < count($new) && $old[$head] === $new[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < count($old) - $head && $tail < count($new) - $head && $old[count($old) - 1 - $tail] === $new[count($new) - 1 - $tail]) {
            $tail++;
        }
        $lines = ['--- baseline', '+++ current'];
        for ($i = 0; $i < $head; $i++) {
            $lines[] = ' '.$old[$i];
        }
        for ($i = $head; $i < count($old) - $tail; $i++) {
            $lines[] = '-'.$old[$i];
        }
        for ($i = $head; $i < count($new) - $tail; $i++) {
            $lines[] = '+'.$new[$i];
        }
        for ($i = count($old) - $tail; $i < count($old); $i++) {
            $lines[] = ' '.$old[$i];
        }

        return implode("\n", $lines);
    }

    /**
     * Required checks are trusted host closures, never command strings or JSON callbacks.
     * An exact completed retry may omit callbacks: it neither reruns checks nor writes receipts.
     *
     * @param  array<string, string>  $inputPaths
     * @param  array<string, Closure>  $registry
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, removed: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
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
        Checkout::inspect($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $plan->claim, $plan->removals);
        $selected = SafeFilesystem::path($plan->root, '.mod-maintenance/journal-'.hash('sha256', $intent).'.json');
        if (file_exists($selected)) {
            $claim = Journal::reopen($plan->root, $plan->appId, $plan->baseline, $plan->target, $inputPaths, $plan->compatibility, $intent, $exclusiveCheckout, $plan->claim, $plan->removals);
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
     * @param  list<string>  $claim  The same reviewed claim list the interrupted plan was made with.
     * @param  array<string, string>  $removals  The same removal policy the interrupted plan was made with.
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, removed: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     *
     * @api
     */
    public static function resume(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, string $intent, array $registry, bool $exclusiveCheckout, array $claim = [], array $removals = []): array
    {
        self::boundary($intent, $exclusiveCheckout);
        FilePlan::between($appId, $root, $baseline, $target, [], [], $compatibility, claim: $claim, removals: $removals);
        self::registry($registry, $target->checks, false);
        $orphans = Orphans::inspect($root, $target);
        if ($orphans !== []) {
            return self::blocked(null, $orphans);
        }
        $journal = Journal::reopen($root, $appId, $baseline, $target, $inputPaths, $compatibility, $intent, $exclusiveCheckout, $claim, $removals);
        try {
            return self::execute($journal, $registry);
        } finally {
            $journal->close();
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
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, removed: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     */
    private static function execute(Journal $claim, array $registry): array
    {
        $plan = $claim->plan();
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans);
        }
        $files = ['written' => [], 'reconciled' => [], 'removed' => []];
        if (($complete = Finalization::completed($claim)) !== null) {
            return $complete + $files + ['plan' => $plan->identity(), 'orphans' => []];
        }
        self::registry($registry, $plan->target->checks);
        $claim->assertVerifierInputs();
        InstalledRecord::baseline($claim->installed(), $plan);
        $files = FileRepair::apply($claim) + ['removed' => FileRepair::remove($claim)];
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans, $files);
        }
        $committed = false;
        try {
            $result = Finalization::record($claim, $registry, beforeRecord: function () use ($plan, &$orphans): void {
                $orphans = Orphans::inspect($plan->root, $plan->target);
                if ($orphans !== []) {
                    throw new RuntimeException('Unresolved maintenance candidates block completion.');
                }
            });
            $committed = $result['status'] === 'completed';
        } catch (RuntimeException $exception) {
            if ($orphans === []) {
                throw $exception;
            }
            FileRepair::restore($claim, $files['removed']);
            $files['removed'] = [];

            return self::blocked($plan->identity(), $orphans, $files);
        } finally {
            if (! $committed && $files['removed'] !== []) {
                // Removed files come back until the update is recorded; a retry removes them again.
                FileRepair::restore($claim, $files['removed']);
                $files['removed'] = [];
            }
        }
        $orphans = Orphans::inspect($plan->root, $plan->target);
        if ($orphans !== []) {
            return self::blocked($plan->identity(), $orphans, $files);
        }

        return $result + $files + ['plan' => $plan->identity(), 'orphans' => []];
    }

    /**
     * @param  list<array{path: string, kind: string}>  $orphans
     * @param  array{written: list<string>, reconciled: list<string>, removed: list<string>}  $files
     * @return array{status: string, no_op: bool, plan: ?string, receipt: ?string, written: list<string>, reconciled: list<string>, removed: list<string>, checks: array<string, string>, orphans: list<array{path: string, kind: string}>}
     */
    private static function blocked(?string $plan, array $orphans, array $files = ['written' => [], 'reconciled' => [], 'removed' => []]): array
    {
        return ['status' => 'orphaned', 'no_op' => false, 'plan' => $plan, 'receipt' => null, 'checks' => [], 'orphans' => $orphans] + $files;
    }
}
