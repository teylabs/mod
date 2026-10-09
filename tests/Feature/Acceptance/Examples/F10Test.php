<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F10 selects React variants and shares the correctly cased identity with PHP', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w, 'react');
        $result = $w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(Frontend::output('F10'));
        expect($w->read('app/Modules/Inventory/resources/js/pages/widget/index.tsx'))->toContain('export default function Widget()')
            ->and($w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'))->toContain("Inertia::render('Inventory::widget/index'");
    });
});
