<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P5 generates anonymous and class Blade components in the module', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Models/.gitkeep', '');
        $anonymous = $w->artisan('mod:component', ['name' => 'Inventory:StockBadge', '--view' => true]);
        expect($anonymous->exitCode)->toBe(0)
            ->and(trim((string) preg_replace('/[ \t]+$/m', '', $anonymous->normalisedOutput())))->toBe('INFO  Component [app/Modules/Inventory/resources/views/components/stock-badge.blade.php] created successfully. Use it as <x-inventory::stock-badge />.')
            ->and($w->read('app/Modules/Inventory/resources/views/components/stock-badge.blade.php'))->toStartWith('<div>')
            ->and($w->exists('app/Modules/Inventory/View/Components/StockBadge.php'))->toBeFalse();
        $class = $w->artisan('mod:component', ['name' => 'Inventory:WidgetTable']);
        expect($class->exitCode)->toBe(0)
            ->and(trim((string) preg_replace('/[ \t]+$/m', '', $class->normalisedOutput())))->toBe("INFO  Component [app/Modules/Inventory/View/Components/WidgetTable.php] created successfully.\n\n   INFO  View [app/Modules/Inventory/resources/views/components/widget-table.blade.php] created successfully. Use it as <x-inventory::widget-table />.")
            ->and($w->read('app/Modules/Inventory/View/Components/WidgetTable.php'))->toContain('namespace App\\Modules\\Inventory\\View\\Components;', "return view('inventory::components.widget-table');")
            ->and($w->read('app/Modules/Inventory/resources/views/components/widget-table.blade.php'))->toStartWith('<div>');
    });
});
