<?php

use Laravel\Mcp\Request;
use Tey\Mod\Boost\InventoryTool;
use Tey\Mod\Boost\PlanTool;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('A3 returns the exact tab plan through Boost without changing any file', function () {
    Workspace::run(null, function (Workspace $w) {
        FrontendScenario::tabs($w);
        $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $before = Scenario::bytes($w);
        $tool = app(PlanTool::class);
        $data = Scenario::data($tool->handle(new Request([
            'command' => 'mod:resource-tabs.tab', 'arguments' => ['Inventory:Widget', 'History'],
        ])), $tool);
        $expected = json_decode($w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data)->toBe($expected)
            ->and(array_column($data['files'], 'alias'))->toBe(['page', 'view'])
            ->and(array_column($data['files'], 'path'))->toBe([
                'app/Modules/Inventory/ViewModels/WidgetHistoryViewModel.php',
                'app/Modules/Inventory/resources/js/pages/Widget/History.vue',
            ])->and($data['inserts'][0]['into'])->toBe('app/Modules/Inventory/ViewModels/ManageWidgetViewModel.php')
            ->and($data['inserts'][0]['at'])->toBe('tabs')
            ->and($data['would_write'])->toBeTrue()
            ->and(Scenario::bytes($w))->toBe($before);
    });
});

it('A3 returns the same inventory as mod:list --json without changing files', function () {
    Workspace::run(null, function (Workspace $w) {
        Scenario::inventory($w);
        $before = Scenario::bytes($w);
        $expected = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $tool = app(InventoryTool::class);
        expect(Scenario::data($tool->handle(new Request), $tool))->toBe($expected)
            ->and(Scenario::bytes($w))->toBe($before);
    });
});
