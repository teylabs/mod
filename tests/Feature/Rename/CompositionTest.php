<?php

use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\EditComposer;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('composes disjoint original byte edits and refuses overlaps stale bytes or wrong locations', function (string $case) {
    Workspace::run(null, function (Workspace $w) use ($case) {
        S::setup($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $source = $w->read($path);
        $offset = strpos($source, 'Widget');
        $edits = [new Edit($path, $offset, 'Widget', 'Gadget', 'declaration', 3)];
        $edits[] = match ($case) {
            'disjoint' => new Edit($path, strpos($source, '{}'), '{}', '{ /* custom */ }', 'body', 3),
            'overlap' => new Edit($path, $offset + 1, 'idget', 'Thing', 'body', 3),
            'stale' => new Edit($path, strpos($source, '{}'), 'XX', 'new', 'body', 3),
            default => new Edit($path, strpos($source, '{}'), '{}', 'new', 'body', 2),
        };
        $result->plan->rename['rewrites'] = [];
        $composed = (new EditComposer)->compose($result->plan, $result->inputs, [new Contribution($edits)]);
        expect($composed->plan->wouldWrite)->toBe($case === 'disjoint');
        if ($case === 'disjoint') {
            expect($composed->bodies[$path])->toBe(str_replace(['Widget', '{}'], ['Gadget', '{ /* custom */ }'], $source));
        }
        expect($w->read($path))->toBe($source);
    });
})->with(['disjoint', 'overlap', 'stale', 'line']);
