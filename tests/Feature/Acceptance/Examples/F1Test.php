<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F1 writes the exact minimal Vue page and render instruction', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $path = 'app/Modules/Inventory/resources/js/pages/Widget/Index.vue';
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read($path)))->toBe(Frontend::source('F1', $path." (no template of the app's own: mod's minimal page)")."\n")
            ->and($result->normalisedOutput())->toBe("\n   INFO  Page [{$path}] created successfully. Render it with Inertia::render('Inventory::Widget/Index').  \n\n");
    });
});
