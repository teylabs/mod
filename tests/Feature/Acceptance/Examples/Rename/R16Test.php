<?php

use Tey\Mod\Rename\Git\Transaction;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Result;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R16 preserves changed input bytes permissions membership and index after confirmation', function (string $kind) {
    Workspace::run(null, function (Workspace $w) use ($kind) {
        S::setup($w);
        $source = 'app/Modules/Inventory/Models/Widget.php';
        $transaction = new Transaction($w->root->path);
        $messages = [];
        $show = function (Result $result) use (&$messages): void {
            $messages = [...$messages, ...array_column($result->plan->warnings, 'message')];
        };
        $expected = '';
        $confirm = function () use ($w, $source, $kind, &$expected): bool {
            if ($kind === 'membership') {
                $w->write('routes/editor.php', '<?php // editor');
            } elseif ($kind === 'index') {
                $w->write('notes.txt', 'outside stage');
                S::git($w, ['add', '--', 'notes.txt']);
            } else {
                $w->write($source, $w->read($source).'// editor');
            }
            $expected = $w->read($source);

            return true;
        };
        expect($transaction->execute(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only', yes: true), app(Planner::class)->build(...), $show, $confirm))->toBe(1)
            ->and($w->read($source))->toBe($expected)
            ->and($w->exists('app/Modules/Inventory/Models/Gadget.php'))->toBeFalse()
            ->and($w->exists('.git/mod-rename/journal.json'))->toBeFalse()
            ->and(implode(' ', $messages))->toContain('mod:rename inputs changed after the preview. Run the preview again. Nothing was written.');
    });
})->with(['membership', 'index', 'bytes']);
