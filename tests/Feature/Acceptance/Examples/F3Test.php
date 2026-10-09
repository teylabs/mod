<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F3 writes twelve CRUD members and uses page identities in the controller', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w);
        $result = $w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $path = 'app/Modules/Inventory/resources/js/pages/Widget/Index.vue';
        expect(str_replace("\r\n", "\n", $w->read($path)))->toBe(Frontend::source('F3', $path))
            ->and($result->normalisedOutput())->toContain('will write 12 files for Inventory:Widget', 'indexPage', 'createPage', 'editPage', 'showPage')
            ->and($w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'))->toContain("Inertia::render('Inventory::Widget/Index'", "Inertia::render('Inventory::Widget/Edit'");
    });
});
