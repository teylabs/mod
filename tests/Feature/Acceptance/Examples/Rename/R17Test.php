<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\ExecutionScenario as E;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R17 applies explicit app-owned page roots and their exact render identities', function () {
    Workspace::run(null, function (Workspace $w) {
        $moves = E::crud($w, 'Catalog:Widget', true);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Catalog:Widget', '--scaffold' => 'crud', '--yes' => true])->assertSuccessful();
        foreach ($moves as $from => $to) {
            expect($w->exists($from))->toBeFalse()->and($w->exists($to))->toBeTrue();
        }
        expect($w->read('resources/js/pages/Catalog/Widget/Show.vue'))->toBe('<template>Widget user copy</template>')
            ->and($w->read('app/Modules/Catalog/Http/Controllers/WidgetController.php'))->toContain("Inertia::render('Catalog/Widget/Show'");
    });
});
