<?php

namespace Tey\Mod\Rename\Tables;

use Illuminate\Console\Command;
use Tey\Mod\Rename\Request;

use function Laravel\Prompts\confirm;

/** @internal Settle the optional migration before rebuilding the final plan. */
final readonly class Preparation implements \Tey\Mod\Rename\Preparation
{
    public function __construct(private Inspector $inspector) {}

    public function prepare(Request $request, callable $build, Command $command): Request
    {
        if ($request->tableMigration || ! $request->interactive || $request->recover) {
            return $request;
        }
        $result = $build($request);
        if (! $result->plan->wouldWrite || $result->inputs === null) {
            return $request;
        }
        $changes = array_values(array_filter($this->inspector->inspect($result->inputs), static fn (ModelTable $table): bool => $table->changes()));
        // The boolean flag selects all changes. Do not silently select a subset.
        if ($changes === []) {
            return $request;
        }
        $pairs = array_map(static fn (ModelTable $table): string => "{$table->old} to {$table->new}", $changes);
        if (! confirm('Create a reversible rename-table migration from '.implode(', ', $pairs).'?', default: false)) {
            return $request;
        }

        return new Request($request->old, $request->new, $request->scaffold, $request->answers, true, $request->recover, $request->yes, $request->interactive);
    }
}
