<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\PlacementScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P11 places a shared contract outside the module and keeps it on the next run', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        putenv('COLUMNS=72');
        $w->write('app/Modules/Inventory/ViewModels/.gitkeep', '');
        $w->write('app/Modules/Knowledge/ViewModels/.gitkeep', '');
        $w->write('stubs/mod.view-model.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }} {}\n");
        $w->write('stubs/mod.interface.stub', "<?php\n\nnamespace {{ namespace }};\n\ninterface {{ class }} {}\n");
        Mod::stubs()->for('view-model', \Tey\Mod\Generation\Stub::file($w->root->path('stubs/mod.view-model.stub')));
        Mod::scaffold('typed-page', fn (Scaffold $s) => $s
            ->makes('view-model', name: '{name}PageViewModel', as: 'page')
            ->makes('interface', name: 'Support/PageData', ungrouped: true, as: 'contract', existing: 'keep'));
        $first = $w->artisan('mod:typed-page', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($first->normalisedOutput())->toEqualText(PlacementScenario::output('mod:typed-page', 'Inventory:Widget', 2, [
            'app/Modules/Inventory/ViewModels/WidgetPageViewModel.php' => 'page',
            'app/Support/PageData.php' => 'contract (ungrouped)',
        ], [
            ['INFO', 'View model [app/Modules/Inventory/ViewModels/WidgetPageViewModel.php] created successfully.'],
            ['INFO', 'Interface [app/Support/PageData.php] created successfully.'],
        ]));
        expect($first->normalisedOutput())->toContain('will write 2 files for Inventory:Widget.', 'contract (ungrouped)')
            ->and($w->read('app/Modules/Inventory/ViewModels/WidgetPageViewModel.php'))->toEqualText("<?php\n\nnamespace App\\Modules\\Inventory\\ViewModels;\n\nclass WidgetPageViewModel {}\n")
            ->and($w->read('app/Support/PageData.php'))->toEqualText("<?php\n\nnamespace App\\Support;\n\ninterface PageData {}\n");
        $w->write('app/Support/PageData.php', "<?php\r\n// shared contract edited by the app\r\n");
        $second = $w->artisan('mod:typed-page', ['name' => 'Knowledge:Document', '--force' => true])->assertSuccessful();
        expect($second->normalisedOutput())->toContain('will write 1 file for Knowledge:Document.', 'contract (kept, exists)')
            ->and($w->read('app/Support/PageData.php'))->toBe("<?php\r\n// shared contract edited by the app\r\n")
            ->and($w->read('app/Modules/Knowledge/ViewModels/DocumentPageViewModel.php'))->toEqualText("<?php\n\nnamespace App\\Modules\\Knowledge\\ViewModels;\n\nclass DocumentPageViewModel {}\n");
        $json = json_decode($w->artisan('mod:typed-page', ['name' => 'Inventory:Another', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['files'][1])->toMatchArray(['group' => null, 'existing' => 'keep', 'exists' => true])
            ->and($json['would_write'])->toBeTrue()->and($w->exists('app/Modules/Inventory/ViewModels/AnotherPageViewModel.php'))->toBeFalse();
    });
});
