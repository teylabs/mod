<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F2 writes the exact minimal React page using kebab-case paths', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w, 'react');
        $path = 'app/Modules/Inventory/resources/js/pages/widget/index.tsx';
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read($path)))->toBe(Frontend::source('F2', $path)."\n")
            ->and($result->normalisedOutput())->toBe("\n   INFO  Page [{$path}] created successfully. Render it with Inertia::render('Inventory::widget/index').  \n\n");
    });
});
