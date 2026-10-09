<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P4 generates a module view with its callable identity', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Models/.gitkeep', '');
        $w->write('stubs/view.stub', '<div>Widget</div>');
        $result = $w->artisan('mod:view', ['name' => 'Inventory:widgets.show']);
        expect($result->exitCode)->toBe(0, $result->output)
            ->and(trim((string) preg_replace('/[ \t]+$/m', '', $result->normalisedOutput())))->toBe("INFO  View [app/Modules/Inventory/resources/views/widgets/show.blade.php] created successfully. Use it with view('inventory::widgets.show').")
            ->and($w->read('app/Modules/Inventory/resources/views/widgets/show.blade.php'))->toBe('<div>Widget</div>');
    });
});
