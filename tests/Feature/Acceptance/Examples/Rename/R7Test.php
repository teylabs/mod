<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R7 refuses tracked staged and untracked dirt without changing bytes or index', function (string $kind) {
    Workspace::run(null, function (Workspace $w) use ($kind) {
        S::setup($w);
        $w->write('routes/web.php', '<?php // original');
        S::commit($w);
        $path = $kind === 'untracked' ? 'notes.txt' : 'routes/web.php';
        $w->write($path, 'outside edit');
        if ($kind === 'staged') {
            S::git($w, ['add', '--', $path]);
        }
        $index = file_get_contents($w->root->path('.git/index'));
        $status = S::git($w, ['status', '--porcelain', '-z']);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertFailed();
        expect($w->read($path))->toBe('outside edit')->and(file_get_contents($w->root->path('.git/index')))->toBe($index)->and(S::git($w, ['status', '--porcelain', '-z']))->toBe($status);
    });
})->with(['tracked', 'staged', 'untracked']);
