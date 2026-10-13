<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Routing\RegistersRoutes;
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

it('lists effective API prefixes for defaults registrations and separately filtered calls', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['Inventory', 'Knowledge', 'Billing'] as $group) {
            foreach (['web', 'api', 'console'] as $method) {
                $w->write("app/Modules/{$group}/routes/{$method}.php", '<?php');
            }
        }
        $w->write('package/routes/api.php', '<?php');
        Mod::routeFiles('versioned', $w->root->path.'/package/routes', apiPrefix: 'v1');
        Mod::routeRegistrar('unprefixed', InventoryPrefixRoutes::class, apiPrefix: '');
        $read = fn (): array => json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $before = $read();
        $api = array_column(array_filter($before['routes'], fn (array $route): bool => $route['middleware_group'] === 'api'), 'api_prefix', 'group');
        expect($api)->toBe(['Billing' => 'api', 'Inventory' => 'api', 'Knowledge' => 'api', 'unprefixed' => '', 'versioned' => 'v1']);
        Mod::routes(only: ['Inventory', 'versioned', 'unprefixed'], apiPrefix: 'v2');
        Mod::routes(except: ['Inventory', 'versioned', 'unprefixed', 'Billing'], apiPrefix: '');
        $data = $read();
        $api = array_column(array_filter($data['routes'], fn (array $route): bool => $route['middleware_group'] === 'api'), 'api_prefix', 'group');
        expect($api)->toBe(['Billing' => 'api', 'Inventory' => 'v2', 'Knowledge' => '', 'unprefixed' => '', 'versioned' => 'v1']);
        foreach ($data['routes'] as $route) {
            if ($route['middleware_group'] !== 'api') {
                expect($route)->not->toHaveKey('api_prefix');
            }
        }
        $schema = (new InventorySectionRegistry)->schema();
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        foreach ($data['routes'] as &$route) {
            unset($route['api_prefix']);
        }
        unset($route);
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        $w->artisan('mod:list')->assertSuccessful()
            ->expectsOutputToContain('API prefix: api')
            ->expectsOutputToContain('API prefix: v1')
            ->expectsOutputToContain('API prefix: v2')
            ->expectsOutputToContain('API prefix: (none)');
    });
});

class InventoryPrefixRoutes implements RegistersRoutes
{
    public static function web(): void {}

    public static function api(): void {}
}
