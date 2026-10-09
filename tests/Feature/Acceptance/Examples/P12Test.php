<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\PlacementScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P12 resolves the HTTP member group from a question independently of the domain', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        putenv('COLUMNS=72');
        $manifest = json_decode($w->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['autoload']['psr-4']['Domain\\'] = 'src/Domain/';
        $w->write('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $w->write('src/Domain/Inventory/Models/.gitkeep', '');
        $w->write('app/Modules/Backoffice/Http/Controllers/.gitkeep', '');
        Mod::scaffold('admin-crud', fn (Scaffold $s) => $s
            ->asks('area', type: 'text', label: 'Which application module holds the admin pages?', default: 'Backoffice')
            ->makes('model')
            ->makes('controller', name: '{name}Controller', group: '{{ area }}'));
        $preview = json_decode($w->artisan('mod:admin-crud', ['name' => 'Inventory:Widget', '--area' => 'Backoffice', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($preview['files'], 'group'))->toBe(['Inventory', 'Backoffice']);
        $result = $w->artisan('mod:admin-crud', ['name' => 'Inventory:Widget', '--area' => 'Backoffice'])->assertSuccessful();
        expect($result->normalisedOutput())->toEqualText(PlacementScenario::output('mod:admin-crud', 'Inventory:Widget', 2, [
            'src/Domain/Inventory/Models/Widget.php' => 'model',
            'app/Modules/Backoffice/Http/Controllers/WidgetController.php' => 'controller (Backoffice)',
        ], [
            ['INFO', 'Model [src/Domain/Inventory/Models/Widget.php] created successfully.'],
            ['INFO', 'Controller [app/Modules/Backoffice/Http/Controllers/WidgetController.php] created successfully.'],
        ]));
        expect($result->normalisedOutput())->toContain('controller (Backoffice)')
            ->and($w->read('src/Domain/Inventory/Models/Widget.php'))->toContain('namespace Domain\\Inventory\\Models;')
            ->and($w->read('app/Modules/Backoffice/Http/Controllers/WidgetController.php'))->toContain('namespace App\\Modules\\Backoffice\\Http\\Controllers;');
    });
});
