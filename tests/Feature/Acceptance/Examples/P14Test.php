<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P14 loads configured modules first and reports an unknown order entry', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.routes.order', ['Billing', 'Knowledge', 'Missing']);
        foreach (['Inventory', 'Agents', 'Knowledge', 'Billing'] as $module) {
            $w->write("app/Modules/{$module}/routes/web.php", "<?php app('config')->push('route_trace', '{$module}');");
        }
        config()->set('route_trace', []);
        Mod::routes();
        expect(config('route_trace'))->toBe(['Billing', 'Knowledge', 'Agents', 'Inventory']);
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['routes'], 'group'))->toBe(['Billing', 'Knowledge', 'Agents', 'Inventory']);
        expect(array_column($json['routes'], 'order'))->toBe([1, 2, 3, 4]);
        $w->artisan('mod:list')->assertSuccessful()->expectsOutputToContain('Missing');
    });
});
