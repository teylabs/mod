<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F4 shares a file identity through the tab tree and writes nine members', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::tabs($w);
        $result = $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($result->normalisedOutput())->toEqualText(Frontend::output('F4'));
        expect($result->normalisedOutput())->toContain('will write 9 files and 6 inserts', 'tab.Overview.view')
            ->and($w->read('app/Modules/Inventory/resources/js/pages/Widget/Notes.vue'))->toEqualText(Frontend::source('F4', 'app/Modules/Inventory/resources/js/pages/Widget/Notes.vue'))
            ->and($w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'))->toContain("Inertia::render('Inventory::Widget/Notes'");
    });
});
