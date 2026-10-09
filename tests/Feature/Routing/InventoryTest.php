<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('pins every route inventory key and shows unloaded routes with an actionable notice', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/routes/web.php', '<?php');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['routes'])->toBe([['group' => 'Inventory', 'entrypoint' => 'app/Modules/Inventory/routes/web.php', 'kind' => 'file', 'middleware_group' => 'web', 'order' => 1, 'loaded_by' => null]]);
        $schema = (new InventorySectionRegistry)->schema();
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        foreach (array_keys($data['routes'][0]) as $key) {
            $missing = $data;
            unset($missing['routes'][0][$key]);
            expect(JsonSchema::errors($missing, $schema))->toContain('$.routes[0].'.$key.' is required');
        }
        $w->artisan('mod:list')->assertSuccessful()->expectsOutputToContain("Mod::routes() isn't called.");
        Mod::routes();
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['routes'][0]['loaded_by'])->toContain('InventoryTest.php:');
    });
});

it('keeps custom layouts without a routes root listable', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'custom');
        Mod::layout('custom')->path('app/{area}')->mounts('app', 'App\\', 'app')
            ->generates('class', in: '@area/Classes');
        $w->write('app/Inventory/Classes/Example.php', '<?php');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['routes'])->toBe([]);
    });
});
