<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P8 generates the exact registrar and discovers implementations by contract', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->artisan('mod:route-registrar', ['module' => 'Inventory'])->assertSuccessful()
            ->expectsOutputToContain('Route registrar [app/Modules/Inventory/Http/Routing/InventoryRoutes.php] created successfully.');
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Inventory/Http/Routing/InventoryRoutes.php')))->toBe(str_replace("\r\n", "\n", <<<'PHP'
<?php

namespace App\Modules\Inventory\Http\Routing;

use Illuminate\Support\Facades\Route;
use Tey\Mod\Routing\RegistersRoutes;

class InventoryRoutes implements RegistersRoutes
{
    public static function web(): void
    {
        // mod:routes
    }

    public static function api(): void
    {
        // mod:api-routes
    }
}
PHP
."\n"));
        $namespace = 'RouteFixtures'.bin2hex(random_bytes(4));
        $w->write('app/Modules/Inventory/Http/Routing/Unexpected.php', "<?php namespace {$namespace}; class Unexpected implements \Tey\Mod\Routing\RegistersRoutes { public static function web(): void { config()->push('route_trace', 'registrar.web'); } public static function api(): void { config()->push('route_trace', 'registrar.api'); } }");
        $w->write('app/Modules/Inventory/Http/Routing/Imposter.php', "<?php namespace {$namespace}; class Imposter { public static function web(): void { throw new \RuntimeException('imposter called'); } }");
        $w->write('app/Modules/Inventory/routes/web.php', "<?php config()->push('route_trace', 'file.web');");
        $w->write('app/Modules/Inventory/routes/api.php', "<?php config()->push('route_trace', 'file.api');");
        config()->set('route_trace', []);
        $w->artisan('mod:list', ['--json' => true])->assertSuccessful();
        expect(config('route_trace'))->toBe([]);
        Mod::routes();
        expect(config('route_trace'))->toBe(['file.web', 'file.api', 'registrar.web', 'registrar.api']);
    });
});
