<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P12 resolves the HTTP member group from a question independently of the domain', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        putenv('COLUMNS=72');
        $w->write('src/Domain/Inventory/Models/.gitkeep', '');
        $w->write('app/Modules/Backoffice/Http/Controllers/.gitkeep', '');
        Mod::scaffold('admin-crud', fn (Scaffold $s) => $s
            ->asks('area', type: 'text', label: 'Which application module holds the admin pages?', default: 'Backoffice')
            ->makes('model')
            ->makes('controller', name: '{name}Controller', group: '{{ area }}')
            ->makes('job', name: 'Index{name}'));
        $preview = json_decode($w->artisan('mod:admin-crud', ['name' => 'Inventory:Widget', '--area' => 'Backoffice', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($preview['files'], 'group'))->toBe(['Inventory', 'Backoffice', 'Inventory']);
        $result = $w->artisan('mod:admin-crud', ['name' => 'Inventory:Widget', '--area' => 'Backoffice'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('controller (Backoffice)')
            ->and($w->read('src/Domain/Inventory/Models/Widget.php'))->toContain('namespace Domain\\Inventory\\Models;')
            ->and($w->read('app/Modules/Backoffice/Http/Controllers/WidgetController.php'))->toContain('namespace App\\Modules\\Backoffice\\Http\\Controllers;')
            ->and($w->exists('src/Domain/Inventory/Jobs/IndexWidget.php'))->toBeTrue();
    });
});
