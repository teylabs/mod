<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\ExecutionScenario as E;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R1 applies exactly ten reviewed moves and preserves edited bodies and page copy', function () {
    Workspace::run(null, function (Workspace $w) {
        $moves = E::crud($w);
        $preview = S::preview($w, ['--scaffold' => 'crud']);
        expect($preview['warnings'])->toBe([])->and(array_column($preview['moves'], 'to', 'from'))->toBe($moves);
        $head = S::git($w, ['rev-parse', 'HEAD']);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'crud', '--yes' => true])->assertSuccessful()->expectsOutputToContain('Renamed Inventory:Widget -> Inventory:Gadget. Review the staged diff before committing.');
        foreach ($moves as $from => $to) {
            expect($w->exists($from))->toBeFalse()->and($w->exists($to))->toBeTrue();
            if (str_ends_with($to, '.vue')) {
                expect($w->read($to))->toBe('<template>Widget user copy</template>');
            }
        }
        expect($w->read('app/Modules/Inventory/Http/Controllers/GadgetController.php'))->toContain('show(Gadget $widget)', "Inertia::render('Inventory::Gadget/Show'", "['item' => \$widget]")
            ->and($w->read('app/Modules/Inventory/Models/Gadget.php'))->toContain('/* edited body */')
            ->and(S::git($w, ['diff', '--name-only']))->toBe('')
            ->and(S::git($w, ['rev-parse', 'HEAD']))->toBe($head);
    });
});
