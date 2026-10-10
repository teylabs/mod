<?php

use Tey\Mod\Rename\Git\Transaction;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\ExecutionScenario as E;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

// Windows runs the first/last move, rewrite and stage boundaries. Linux still
// exhausts all 28 boundaries; Windows recovery tests also kill real workers at
// durable file, journal and index checkpoints. Repeating the full ten-file CRUD
// preparation for every equivalent boundary costs over five minutes on Windows.

it('R15 restores every byte path permission and raw index at each operation boundary', function (int $boundary) {
    Workspace::run(null, function (Workspace $w) use ($boundary) {
        E::crud($w, 'Catalog:Widget');
        $paths = array_values(array_filter($w->files(), fn (string $path): bool => ! str_starts_with($path, '.git/')));
        $bytes = array_combine($paths, array_map($w->read(...), $paths));
        $modes = array_combine($paths, array_map(fn (string $path): int => fileperms($w->root->path($path)) & 0777, $paths));
        $index = file_get_contents($w->root->path('.git/index'));
        $transaction = new Transaction($w->root->path, static function (string $phase) use ($boundary): void {
            if ($phase === 'applied:'.$boundary) {
                throw new RuntimeException('injected operation failure');
            }
        });
        $messages = [];
        $show = function (Result $result) use (&$messages): void {
            $messages = [...$messages, ...array_column($result->plan->warnings, 'message')];
        };
        expect($transaction->execute(new Request('Inventory:Widget', 'Catalog:Widget', 'crud', yes: true), app(Planner::class)->build(...), $show, fn () => true))->toBe(1);
        expect(array_combine($paths, array_map($w->read(...), $paths)))->toBe($bytes)
            ->and(array_combine($paths, array_map(fn (string $path): int => fileperms($w->root->path($path)) & 0777, $paths)))->toBe($modes)
            ->and(file_get_contents($w->root->path('.git/index')))->toBe($index)
            ->and(array_values(array_filter($w->files(), fn (string $path): bool => ! str_starts_with($path, '.git/'))))->toBe($paths)
            ->and(S::git($w, ['status', '--porcelain']))->toBe('')
            ->and(implode(' ', $messages))->toContain('All changes were rolled back. Nothing was renamed.');
    });
})->with(PHP_OS_FAMILY === 'Windows' ? [1, 10, 11, 17, 18, 28] : range(1, 28));
