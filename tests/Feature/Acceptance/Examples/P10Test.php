<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P10 starts a missing route file by alias with a machine readable insert plan', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('route-example', function (\Tey\Mod\Scaffolds\Scaffold $s) {
            $s->makes('class');
            $s->part('tab', fn (\Tey\Mod\Scaffolds\Part $p) => $p->inserts(into: 'routes', at: 'routes', stub: 'tab-route'));
        });
        $w->write('stubs/mod.insert.tab-route.stub', "Route::get('history', fn () => 'history');\n");
        $preview = $w->artisan('mod:route-example.tab', ['name' => 'Inventory:Widget', 'item' => 'History', '--dry-run' => true, '--json' => true])->assertSuccessful();
        $json = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['files'], 'path'))->toContain('app/Modules/Inventory/routes/web.php');
        expect($json['inserts'][0]['into'])->toBe('app/Modules/Inventory/routes/web.php');
        expect($w->exists('app/Modules/Inventory/routes/web.php'))->toBeFalse();
    });
});
