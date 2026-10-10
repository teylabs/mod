<?php

use Tey\Mod\Rename\Git\Transaction;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\BoundedProcess as Process;

function renameRequest(): Request
{
    return new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only', yes: true);
}

it('stages moved declarations and external references without committing', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('routes/web.php', "<?php\nuse App\\Modules\\Inventory\\Models\\Widget;\n// keep my body\n");
        S::commit($w);
        $head = S::git($w, ['rev-parse', 'HEAD']);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse()
            ->and($w->read('app/Modules/Inventory/Models/Gadget.php'))->toContain('class Gadget {}')
            ->and($w->read('routes/web.php'))->toContain('Models\\Gadget;', '// keep my body')
            ->and(S::git($w, ['diff', '--name-only']))->toBe('')
            ->and(S::git($w, ['diff', '--cached', '--name-only']))->toContain('Gadget.php', 'routes/web.php')
            ->and(S::git($w, ['rev-parse', 'HEAD']))->toBe($head);
    });
});

it('restores exact bytes permissions and raw index after an ordinary failure', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $source = 'app/Modules/Inventory/Models/Widget.php';
        $w->write($source, str_replace("\n", "\r\n", $w->read($source)));
        chmod($w->root->path($source), 0755);
        S::commit($w);
        $before = $w->read($source);
        $mode = fileperms($w->root->path($source)) & 0777;
        $index = file_get_contents($w->root->path('.git/index'));
        $transaction = new Transaction($w->root->path, function (string $phase): void {
            if ($phase === 'applied:1') {
                throw new RuntimeException('injected');
            }
        });
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and($w->read($source))->toBe($before)
            ->and(fileperms($w->root->path($source)) & 0777)->toBe($mode)
            ->and(file_get_contents($w->root->path('.git/index')))->toBe($index)
            ->and(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('refuses input drift after confirmation and preserves the editor bytes', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $transaction = new Transaction($w->root->path);
        $confirm = function () use ($w): bool {
            $w->write('routes/new.php', '<?php // editor');

            return true;
        };
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, $confirm))->toBe(1)
            ->and($w->read('routes/new.php'))->toBe('<?php // editor')
            ->and($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeTrue()
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeFalse();
    });
});

it('retains recovery state when an outside editor changes an owned destination', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $transaction = new Transaction($w->root->path, function (string $phase) use ($w): void {
            if ($phase === 'applied:1') {
                $w->write('app/Modules/Inventory/Models/Gadget.php', '<?php // outside editor');
                throw new RuntimeException('injected');
            }
        });
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and($w->read('app/Modules/Inventory/Models/Gadget.php'))->toBe('<?php // outside editor')
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeTrue();
        $w->artisan('mod:rename', ['--recover' => true, '--dry-run' => true, '--json' => true])->assertSuccessful();
    });
});

it('recovers a killed process and preserves unrelated working and staged changes', function (string $phase) {
    Workspace::run(null, function (Workspace $w) use ($phase) {
        S::setup($w);
        $source = 'app/Modules/Inventory/Models/Widget.php';
        $before = $w->read($source);
        $originalIndex = S::git($w, ['ls-files', '--stage', '-z']);
        $worker = dirname(__DIR__, 3).'/Fixtures/rename/transaction-worker.php';
        $process = new Process([PHP_BINARY, $worker, $w->root->path, 'execute', $phase], timeout: 60);
        $process->start();
        try {
            expect($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'BARRIER')))->toBeTrue($process->getErrorOutput());
        } finally {
            $process->stop(0, 9);
        }
        expect($process->isRunning())->toBeFalse();
        $w->write('notes.txt', 'outside working change');
        $w->write('staged.txt', 'outside staged change');
        S::git($w, ['add', '--', 'staged.txt']);
        $preview = $w->artisan('mod:rename', ['--recover' => true, '--dry-run' => true, '--json' => true])->assertSuccessful();
        expect(json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR)['would_write'])->toBeTrue();
        $recover = new Process([PHP_BINARY, $worker, $w->root->path, 'recover'], timeout: 60);
        $recover->mustRun();
        expect($w->read($source))->toBe($before)
            ->and($w->read('notes.txt'))->toBe('outside working change')
            ->and($w->read('staged.txt'))->toBe('outside staged change')
            ->and(S::git($w, ['diff', '--cached', '--name-only']))->toBe("staged.txt\n")
            ->and(S::git($w, ['ls-files', '--stage', '-z']))->toContain($originalIndex)
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeFalse();
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
    });
})->with(['prepared', 'applied:1', 'applied:2', 'applied:3']);

it('reports incomplete rollback and allows a later explicit recovery after a rollback fault', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $messages = [];
        $transaction = new Transaction($w->root->path, static function (string $phase): void {
            if ($phase === 'applied:2' || str_starts_with($phase, 'before-restore:')) {
                throw new RuntimeException('injected rollback failure');
            }
        });
        $show = function (Result $result) use (&$messages): void {
            $messages = [...$messages, ...array_column($result->plan->warnings, 'message')];
        };
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), $show, fn () => true))->toBe(1)
            ->and(implode(' ', $messages))->toContain('Rollback incomplete; recovery required', 'Remaining operations:')
            ->and(implode(' ', $messages))->not->toContain('Nothing was renamed');
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('recompares the owned source immediately at an external-editor mutation boundary', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $transaction = new Transaction($w->root->path, static function (string $phase) use ($w): void {
            if (str_starts_with($phase, 'before-move:')) {
                $w->write('app/Modules/Inventory/Models/Widget.php', '<?php // external editor');
            }
        });
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and($w->read('app/Modules/Inventory/Models/Widget.php'))->toBe('<?php // external editor')
            ->and($w->exists('app/Modules/Inventory/Models/Gadget.php'))->toBeFalse()
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeTrue();
    });
});

it('detects new scan-root membership during application and rolls back while preserving it', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $transaction = new Transaction($w->root->path, static function (string $phase) use ($w): void {
            if ($phase === 'applied:1') {
                $w->write('routes/editor.php', '<?php // new outside consumer');
            }
        });
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeTrue()
            ->and($w->read('routes/editor.php'))->toBe('<?php // new outside consumer');
    });
});

it('preserves an editor change arriving while an atomic replacement is being flushed', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $transaction = new Transaction($w->root->path, static function (string $phase) use ($w): void {
            if (str_starts_with($phase, 'temporary:')) {
                $w->write('app/Modules/Inventory/Models/Gadget.php', '<?php // edit during flush');
            }
        });
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and($w->read('app/Modules/Inventory/Models/Gadget.php'))->toBe('<?php // edit during flush');
    });
});

it('detects an externally changed non-executable permission bit after confirmation', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $source = $w->root->path('app/Modules/Inventory/Models/Widget.php');
        $mode = fileperms($source) & 0777;
        $transaction = new Transaction($w->root->path);
        $confirm = static function () use ($source): bool {
            (new Process([PHP_BINARY, '-r', 'chmod($argv[1], 0400);', $source], timeout: 60))->mustRun();

            return true;
        };
        try {
            expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), static function (): void {}, $confirm))->toBe(1)
                ->and(is_file($source))->toBeTrue();
        } finally {
            chmod($source, $mode);
            $destination = $w->root->path('app/Modules/Inventory/Models/Gadget.php');
            if (is_file($destination)) {
                chmod($destination, $mode);
            }
        }
    });
});

it('preserves the original executable Git entry independently of filesystem mode emulation', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $source = 'app/Modules/Inventory/Models/Widget.php';
        S::git($w, ['config', 'core.filemode', 'false']);
        S::git($w, ['update-index', '--chmod=+x', '--', $source]);
        S::git($w, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'Executable fixture']);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertSuccessful();
        expect(S::git($w, ['ls-files', '--stage', '--', 'app/Modules/Inventory/Models/Gadget.php']))->toStartWith('100755 ');
    });
});

it('preserves an outside edit to a not-yet-mutated owned file during rollback', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('routes/web.php', "<?php\nuse App\\Modules\\Inventory\\Models\\Widget;\n");
        S::commit($w);
        $transaction = new Transaction($w->root->path, static function (string $phase) use ($w): void {
            if ($phase === 'applied:1') {
                throw new RuntimeException('injected move failure');
            }
            if ($phase === 'before-restore:app/Modules/Inventory/Models/Widget.php') {
                $w->write('routes/web.php', '<?php // outside editor during rollback');
            }
        });
        $messages = [];
        expect($transaction->execute(renameRequest(), app(Planner::class)->build(...), function (Result $result) use (&$messages): void {
            $messages = [...$messages, ...array_column($result->plan->warnings, 'message')];
        }, fn () => true))->toBe(1);
        expect($w->read('routes/web.php'))->toBe('<?php // outside editor during rollback')
            ->and(implode(' ', $messages))->toContain('Rollback incomplete; recovery required', 'Outside bytes or permissions: routes/web.php')
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeTrue();
    });
});
