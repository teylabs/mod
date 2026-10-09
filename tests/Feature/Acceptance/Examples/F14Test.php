<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F14 exposes component, framework, import and path identities to sibling templates', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::tabs($w);
        $plan = json_decode($w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $layout = array_values(array_filter($plan['files'], fn (array $file) => $file['alias'] === 'layout'))[0];
        expect($layout['identity']['import'])->toBe('@modules/Inventory/resources/js/components/WidgetLayout.vue')
            ->and($layout['path'])->toBe('app/Modules/Inventory/resources/js/components/WidgetLayout.vue');
    });
});
