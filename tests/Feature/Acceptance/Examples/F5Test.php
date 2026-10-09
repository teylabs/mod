<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F5 grows a tab with its Vue page and two inserts', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::tabs($w);
        $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $layout = $w->read('app/Modules/Inventory/resources/js/components/WidgetLayout.vue');
        $result = $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 2 files and 2 inserts')
            ->and($w->read('app/Modules/Inventory/resources/js/pages/Widget/History.vue'))->toContain('WidgetLayout', 'title="History"')
            ->and($w->read('app/Modules/Inventory/resources/js/components/WidgetLayout.vue'))->toBe($layout);
    });
});
