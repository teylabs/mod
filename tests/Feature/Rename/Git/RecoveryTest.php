<?php

use Laravel\Mcp\Request;
use Tey\Mod\Boost\PlanTool;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\GitProbe;
use Tey\Mod\Rename\Process;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Support\Checkpoint;
use Tey\Mod\Tests\Support\JsonSchema;

function transactionWorker(Workspace $w, string $action, string $phase = ''): Process
{
    return new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/rename/transaction-worker.php', $w->root->path, $action, $phase], timeout: 60);
}

function killAtBarrier(Process $process): void
{
    $process->setTimeout(60);
    $process->start();
    try {
        expect(Checkpoint::wait($process))->toBeTrue($process->getErrorOutput());
    } finally {
        $process->stop(0, 9);
    }
    expect($process->isRunning())->toBeFalse();
}

it('serializes worktree execution before planning and reuses a stale kernel lock safely', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $process = transactionWorker($w, 'execute', 'prepared');
        $process->start();
        try {
            expect(Checkpoint::wait($process))->toBeTrue($process->getErrorOutput());
            $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertFailed()->expectsOutputToContain('already running in this worktree');
        } finally {
            $process->stop(0, 9);
        }
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertFailed()->expectsOutputToContain('interrupted transaction');
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
        S::apply($w);
    });
});

it('resumes recovery killed after restoring a path and restores the exact original index', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $index = file_get_contents($w->root->path('.git/index'));
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        killAtBarrier(transactionWorker($w, 'recover', 'restored:app/Modules/Inventory/Models/Widget.php'));
        $preview = $w->artisan('mod:rename', ['--recover' => true, '--dry-run' => true, '--json' => true])->assertSuccessful();
        $json = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['recovery']['phase'])->toBe('rolling-back')->and($json['would_write'])->toBeTrue();
        $schema = json_decode(file_get_contents(dirname(__DIR__, 3).'/Fixtures/schema/rename.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(JsonSchema::errors($json, $schema))->toBe([]);
        transactionWorker($w, 'recover')->mustRun();
        expect(file_get_contents($w->root->path('.git/index')))->toBe($index)->and(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('recovers an atomic file replacement killed with its flushed temporary still present', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'temporary:app/Modules/Inventory/Models/Gadget.php'));
        expect($w->exists('app/Modules/Inventory/Models/Gadget.php.mod-rename-tmp'))->toBeTrue();
        transactionWorker($w, 'recover')->mustRun();
        expect($w->exists('app/Modules/Inventory/Models/Gadget.php.mod-rename-tmp'))->toBeFalse()->and(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('refuses foreign malformed unsafe and corrupt journals without any restoration', function (string $kind) {
    Workspace::run(null, function (Workspace $w) use ($kind) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        $path = '.git/mod-rename/journal.json';
        $journal = json_decode($w->read($path), true, flags: JSON_THROW_ON_ERROR);
        if ($kind === 'foreign') {
            $journal['root'] = '/another/worktree';
        } elseif ($kind === 'unsafe') {
            $journal['directories'][] = '../outside';
        } elseif ($kind === 'metadata') {
            $journal['paths']['.git/config'] = $journal['paths']['app/Modules/Inventory/Models/Widget.php'];
        } elseif ($kind === 'index') {
            $journal['index'] = base64_encode('corrupt');
        }
        $w->write($path, $kind === 'malformed' ? '{broken' : json_encode($journal, JSON_THROW_ON_ERROR));
        $before = $w->read('app/Modules/Inventory/Models/Gadget.php');
        $index = file_get_contents($w->root->path('.git/index'));
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertFailed();
        expect($w->read('app/Modules/Inventory/Models/Gadget.php'))->toBe($before)->and(file_get_contents($w->root->path('.git/index')))->toBe($index)->and($w->exists($path))->toBeTrue();
    });
})->with(['foreign', 'unsafe', 'metadata', 'index', 'malformed']);

it('preserves an outside index conflict and requires explicit repair before recovery', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        $destination = 'app/Modules/Inventory/Models/Gadget.php';
        $expected = $w->read($destination);
        $w->write($destination, '<?php // editor staged');
        S::git($w, ['add', '--', $destination]);
        $index = S::git($w, ['ls-files', '--stage', '-z']);
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertFailed()->expectsOutputToContain('Outside index entry');
        expect($w->read($destination))->toBe('<?php // editor staged')->and(S::git($w, ['ls-files', '--stage', '-z']))->toBe($index);
        $w->write($destination, $expected);
        $journal = json_decode($w->read('.git/mod-rename/journal.json'), true, flags: JSON_THROW_ON_ERROR);
        $entry = explode(' ', $journal['expected_entries'][$destination][0]);
        S::git($w, ['update-index', '--cacheinfo', $entry[0].','.$entry[1].','.$destination]);
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('supports actual linked worktree execution and stores its journal under its own git directory', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $linked = $w->root->path('linked');
        S::git($w, ['worktree', 'add', '-b', 'linked', $linked]);
        $w->write('parent-dirty.txt', 'other worktree edit');
        app()->setBasePath($linked);
        app()->forgetInstance(CompiledLayout::class);
        $state = (new GitProbe)->inspect($linked);
        expect($state->directory)->toContain('/worktrees/')->and($state->clean())->toBeTrue();
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertSuccessful();
        expect(file_get_contents($linked.'/app/Modules/Inventory/Models/Gadget.php'))->toContain('class Gadget')
            ->and($w->read('parent-dirty.txt'))->toBe('other worktree edit')
            ->and(is_file($state->directory.'/mod-rename/lock'))->toBeTrue()
            ->and($w->exists('.git/mod-rename/lock'))->toBeFalse();
    });
});

it('requires no cluster arguments and default-No recovery confirmation', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        $journal = $w->read('.git/mod-rename/journal.json');
        $index = file_get_contents($w->root->path('.git/index'));
        $w->artisan('mod:rename', ['--recover' => true, 'old' => 'Inventory:Widget', '--yes' => true])->assertFailed()->expectsOutputToContain('takes no cluster arguments');
        $w->artisan('mod:rename', ['--recover' => true])->assertFailed()->expectsOutputToContain('requires confirmation');
        Examples::testCase()->artisan('mod:rename', ['--recover' => true])
            ->expectsConfirmation('Restore this interrupted rename?', 'no')->expectsOutput('Rename cancelled. Nothing was written.')->assertSuccessful();
        expect($w->read('.git/mod-rename/journal.json'))->toBe($journal)->and(file_get_contents($w->root->path('.git/index')))->toBe($index);
        $w->artisan('mod:rename', ['--recover' => true, '--json' => true, '--yes' => true])->assertFailed()->expectsOutputToContain('--json is a preview option');
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
    });
});

it('inspects a real recovery journal through mod-plan without locks or mutation', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'applied:1'));
        $journal = $w->read('.git/mod-rename/journal.json');
        $index = file_get_contents($w->root->path('.git/index'));
        app()->instance(Executor::class, new class implements Executor
        {
            public function execute(Tey\Mod\Rename\Request $request, callable $build, callable $show, callable $confirm): int
            {
                throw new LogicException('Tool invoked execution');
            }

            public function recover(Tey\Mod\Rename\Request $request, callable $show, callable $confirm): int
            {
                throw new LogicException('Tool invoked recovery');
            }
        });
        $tool = new PlanTool;
        $data = Scenario::data($tool->handle(new Request(['command' => 'mod:rename', 'arguments' => ['--recover', '--yes']])), $tool);
        expect($data['recovery']['phase'])->toBe('applying')->and($data['would_write'])->toBeTrue()
            ->and($w->read('.git/mod-rename/journal.json'))->toBe($journal)->and(file_get_contents($w->root->path('.git/index')))->toBe($index);
    });
});

it('resumes index restoration killed after linking its exclusively owned index lock', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $index = file_get_contents($w->root->path('.git/index'));
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        killAtBarrier(transactionWorker($w, 'recover', 'index-linked'));
        expect($w->exists('.git/index.lock'))->toBeTrue();
        transactionWorker($w, 'recover')->mustRun();
        expect(file_get_contents($w->root->path('.git/index')))->toBe($index)->and($w->exists('.git/index.lock'))->toBeFalse()->and($w->exists('.git/mod-rename/restore-index'))->toBeFalse();
    });
});

it('preserves a foreign Git index lock and recovers once its owner removes it', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'applied:2'));
        $w->write('.git/index.lock', 'foreign Git lock');
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertFailed()->expectsOutputToContain('locked by another owner');
        expect($w->read('.git/index.lock'))->toBe('foreign Git lock')->and($w->exists('.git/mod-rename/journal.json'))->toBeTrue();
        unlink($w->root->path('.git/index.lock'));
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('recovers a journal replacement killed with a durable intent temporary', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'journal-temporary:rewriting app/Modules/Inventory/Models/Widget.php'));
        expect(glob($w->root->path('.git/mod-rename/journal.json.mod-rename-tmp.*')))->toHaveCount(1);
        transactionWorker($w, 'recover')->mustRun();
        expect(glob($w->root->path('.git/mod-rename/journal.json.mod-rename-tmp.*')))->toBe([])->and(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('cleans a committed interruption without undoing the staged rename or later editor changes', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        killAtBarrier(transactionWorker($w, 'execute', 'committed'));
        $w->write('app/Modules/Inventory/Models/Gadget.php', '<?php // edit after success');
        $index = S::git($w, ['ls-files', '--stage', '-z']);
        $json = json_decode($w->artisan('mod:rename', ['--recover' => true, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['recovery']['phase'])->toBe('committed')->and($json['recovery']['operations'])->toBe([]);
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Gadget.php'))->toBe('<?php // edit after success')->and(S::git($w, ['ls-files', '--stage', '-z']))->toBe($index);
    });
});

it('distinguishes absent and empty files after interruption during restoration', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $source = 'app/Modules/Inventory/resources/js/pages/Widget/Show.vue';
        $w->write($source, '');
        S::commit($w);
        $worker = dirname(__DIR__, 3).'/Fixtures/rename/transaction-worker.php';
        killAtBarrier(new Process([PHP_BINARY, $worker, $w->root->path, 'execute', 'applied:1', 'pages']));
        killAtBarrier(transactionWorker($w, 'recover', 'restore-temporary:'.$source));
        expect($w->read($source.'.mod-rename-tmp'))->toBe('');
        transactionWorker($w, 'recover')->mustRun();
        expect($w->read($source))->toBe('')->and($w->exists($source.'.mod-rename-tmp'))->toBeFalse()->and(S::git($w, ['status', '--porcelain']))->toBe('');
    });
});

it('preserves outside reversions and deletions after a completed checkpoint', function (string $change) {
    Workspace::run(null, function (Workspace $w) use ($change) {
        S::setup($w);
        $source = 'app/Modules/Inventory/Models/Widget.php';
        $destination = 'app/Modules/Inventory/Models/Gadget.php';
        $original = $w->read($source);
        killAtBarrier(transactionWorker($w, 'execute', $change === 'index' ? 'applied:3' : 'applied:2'));
        if ($change === 'delete') {
            unlink($w->root->path($destination));
        } elseif ($change === 'bytes') {
            $w->write($destination, $original);
        } else {
            $journal = json_decode($w->read('.git/mod-rename/journal.json'), true, flags: JSON_THROW_ON_ERROR);
            $hash = explode(' ', $journal['original_entries'][$source])[1];
            S::git($w, ['update-index', '--cacheinfo', '100644,'.trim($hash).','.$destination]);
        }
        $bytes = $w->exists($destination) ? $w->read($destination) : null;
        $index = S::git($w, ['ls-files', '--stage', '-z']);
        $w->artisan('mod:rename', ['--recover' => true, '--yes' => true])->assertFailed()->expectsOutputToContain('Outside');
        expect($w->exists($source))->toBeFalse()->and($w->exists($destination) ? $w->read($destination) : null)->toBe($bytes)->and(S::git($w, ['ls-files', '--stage', '-z']))->toBe($index)->and($w->exists('.git/mod-rename/journal.json'))->toBeTrue();
    });
})->with(['bytes', 'delete', 'index']);
