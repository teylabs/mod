<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Routing\ModRoutes;
use Tey\Mod\Routing\RegistersRoutes;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('orders and selects package files inside the callers route group', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.routes.order', ['billing', 'Inventory']);
        config()->set('route_trace', []);
        $w->write('app/Modules/Inventory/routes/web.php', "<?php config()->push('route_trace', 'module');");
        $w->write('vendor/example/billing/routes/web.php', "<?php config()->push('route_trace', 'package'); \\Illuminate\\Support\\Facades\\Route::get('invoices', fn () => 'ok');");
        $w->write('vendor/example/billing/routes/api.php', "<?php \\Illuminate\\Support\\Facades\\Route::get('invoices', fn () => 'ok');");
        $w->write('vendor/example/billing/routes/console.php', "<?php config()->push('route_trace', 'console');");
        Mod::routeFiles('billing', $w->root->path.'/vendor/example/billing/routes');
        Route::prefix('admin')->middleware('auth')->group(fn () => Mod::routes(except: ['Inventory']));
        expect(config('route_trace'))->toBe(['package', 'console']);
        $entries = app(ModRoutes::class)->entries();
        expect($entries[0]['kind'])->toBe('package')->and($entries[0]['loaded_by'])->not->toBeNull();
        $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn (Illuminate\Routing\Route $route): string => $route->uri());
        expect($routes['admin/invoices']->uri())->toBe('admin/invoices')->and($routes['admin/invoices']->gatherMiddleware())->toBe(['auth', 'web']);
        expect($routes['admin/api/invoices']->uri())->toBe('admin/api/invoices');
        Mod::routes(only: ['Inventory']);
        expect(config('route_trace'))->toBe(['package', 'console', 'module']);
    });
});

it('loads explicitly registered package registrar classes', function () {
    config()->set('route_trace', []);
    Mod::routeRegistrar('billing', PackageRoutes::class);
    Mod::routes(only: ['billing']);
    expect(config('route_trace'))->toBe(['web', 'api']);
    expect(array_column(app(ModRoutes::class)->entries(), 'kind'))->toContain('package');
});

it('applies registration prefixes before call prefixes without changing web or console routes', function (string $kind, ?string $registration, string $call, string $expected) {
    Workspace::run(null, function (Workspace $w) use ($kind, $registration, $call, $expected) {
        config()->set('route_trace', []);
        if ($kind === 'files') {
            $w->write('package/routes/web.php', "<?php \\Illuminate\\Support\\Facades\\Route::get('prefix-web', fn () => 'web');");
            $w->write('package/routes/api.php', "<?php \\Illuminate\\Support\\Facades\\Route::get('prefix-api', fn () => 'api');");
            $w->write('package/routes/console.php', "<?php config()->push('route_trace', 'console');");
            Mod::routeFiles('versioned', $w->root->path.'/package/routes', apiPrefix: $registration);
        } else {
            Mod::routeRegistrar('versioned', PackageRoutes::class, apiPrefix: $registration);
        }
        Mod::routes(only: ['versioned'], apiPrefix: $call);
        $data = json_decode($w->artisan('route:list', ['--path' => 'prefix-', '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data, 'uri'))->toContain('prefix-web', ltrim($expected.'/prefix-api', '/'));
        expect(config('route_trace'))->toBe($kind === 'files' ? ['console'] : ['web', 'api']);
        expect(fn () => Mod::routes(only: ['versioned'], apiPrefix: 'other'))->toThrow(LogicException::class, 'versioned');
    });
})->with(['files', 'registrar'])->with([
    'call prefix' => [null, 'v1', 'v1'],
    'registration prefix' => ['v1', 'api', 'v1'],
    'registration wins' => ['v1', 'v2', 'v1'],
    'empty call prefix' => [null, '', ''],
    'empty registration wins' => ['', 'v2', ''],
    'explicit default wins' => ['api', 'v2', 'api'],
]);

it('does not inspect package files or call registrars with cached routes', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('bootstrap/cache/routes-v7.php', '<?php return [];');
        app()->useBootstrapPath($w->root->path.'/bootstrap');
        app()->forgetInstance('routes.cached');
        expect(app()->routesAreCached())->toBeTrue();
        Mod::routeFiles('missing', $w->root->path.'/missing', apiPrefix: 'v1');
        Mod::routeRegistrar('billing', PackageRoutes::class, apiPrefix: 'v1');
        config()->set('route_trace', []);
        Mod::routes(apiPrefix: 'v2');
        expect(config('route_trace'))->toBe([]);
    });
});

it('rejects duplicate registrations and module group collisions', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Billing/routes/web.php', '<?php');
        Mod::routeFiles('Billing', 'vendor/example/routes');
        expect(fn () => app(ModRoutes::class)->entries())->toThrow(LogicException::class);
        expect(fn () => Mod::routeFiles('Billing', 'other/routes'))->toThrow(LogicException::class);
    });
});

class PackageRoutes implements RegistersRoutes
{
    public static function web(): void
    {
        config()->push('route_trace', 'web');
        Route::get('prefix-web', fn () => 'web');
    }

    public static function api(): void
    {
        config()->push('route_trace', 'api');
        Route::get('prefix-api', fn () => 'api');
    }
}
