<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P10 starts a missing route file by alias with a machine readable insert plan', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('route-example', function (Scaffold $s) {
            $s->makes('class');
            $s->part('tab', fn (Part $p) => $p->inserts(into: 'routes', at: 'routes', stub: 'tab-route'));
        });
        $w->write('stubs/mod.insert.tab-route.stub', "Route::get('history', fn () => 'history');\n");
        $w->artisan('mod:route-example', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $preview = $w->artisan('mod:route-example.tab', ['name' => 'Inventory:Widget', 'value' => 'History', '--dry-run' => true, '--json' => true])->assertSuccessful();
        $json = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['files'], 'path'))->toContain('app/Modules/Inventory/routes/web.php');
        expect($json['inserts'][0]['into'])->toBe('app/Modules/Inventory/routes/web.php');
        expect($w->exists('app/Modules/Inventory/routes/web.php'))->toBeFalse();
    });
});

it('P10 writes and inserts through the routes alias in modules and ddd', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        Mod::scaffold('route-insert', function (Scaffold $s) {
            $s->makes('class')->asks('tabs', type: 'list', default: ['History'])
                ->part('tab', fn (Part $p) => $p->inserts(into: 'routes', at: 'routes', stub: 'tab-route'))
                ->each('tabs', part: 'tab');
        });
        $w->write('stubs/mod.insert.tab-route.stub', "Route::get('history', fn () => 'history');\n");
        $w->artisan('mod:route-insert', ['name' => 'Inventory:Widget'])->assertSuccessful()
            ->expectsOutputToContain('Route file [app/Modules/Inventory/routes/web.php] created successfully.')
            ->expectsOutputToContain("routes won't load");
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Inventory/routes/web.php')))->toBe("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('history', fn () => 'history');\n// mod:routes\n");
    });
})->with(['modules', 'ddd']);

it('P10 inserts into registrar methods and preserves the other method', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('registrar-insert', function (Scaffold $s) {
            $s->makes('class')->asks('tabs', type: 'list', default: ['History'])
                ->part('tab', fn (Part $p) => $p->inserts(into: 'routes.web', at: 'routes', stub: 'registrar-route'))
                ->each('tabs', part: 'tab');
        });
        $w->write('stubs/mod.insert.registrar-route.stub', "        Route::get('history', fn () => 'history');\n");
        $w->artisan('mod:route-registrar', ['module' => 'RegistrarProof'])->assertSuccessful();
        $w->artisan('mod:registrar-insert', ['name' => 'RegistrarProof:Widget'])->assertSuccessful();
        $source = str_replace("\r\n", "\n", $w->read('app/Modules/RegistrarProof/Http/Routing/RegistrarProofRoutes.php'));
        expect($source)->toContain("        Route::get('history', fn () => 'history');\n        // mod:routes")
            ->and($w->exists('app/Modules/RegistrarProof/routes/web.php'))->toBeFalse();
        $w->artisan('mod:registrar-insert.tab', ['name' => 'RegistrarProof:Widget', 'value' => 'History'])->assertFailed()->expectsOutputToContain('Insert already exists');
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/RegistrarProof/Http/Routing/RegistrarProofRoutes.php')))->toBe($source);
    });
});
