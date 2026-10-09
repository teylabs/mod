<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F7 derives card from a multi-dot template and preserves Blade expressions', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $stub = Frontend::source('F7', 'stubs/mod/@module/resources/views/components/card.blade.php.stub');
        $w->write('stubs/mod/@module/resources/views/components/card.blade.php.stub', $stub);
        $path = 'app/Modules/Inventory/resources/views/components/widget-summary.blade.php';
        $result = $w->artisan('mod:card', ['name' => 'Inventory:WidgetSummary'])->assertSuccessful();
        expect($w->read($path))->toBe($stub)
            ->and($result->normalisedOutput())->toBe("\n   INFO  Card [{$path}] created successfully. Use it as <x-inventory::widget-summary />.  \n\n");
    });
});
