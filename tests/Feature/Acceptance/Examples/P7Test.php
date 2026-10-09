<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P7 inherits middleware, URI and name prefixes and separates filtered calls', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['Inventory', 'Knowledge', 'Agents'] as $module) {
            $w->write("app/Modules/{$module}/routes/web.php", "<?php \Illuminate\Support\Facades\Route::get('{$module}', fn () => 'ok')->name('{$module}');");
        }
        Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(fn () => Mod::routes(only: ['Inventory', 'Knowledge']));
        Mod::routes(except: ['Inventory', 'Knowledge']);
        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('admin.Inventory');
        expect($route->uri())->toBe('admin/Inventory')->and($route->middleware())->toBe(['auth', 'can:admin', 'web']);
        expect(Route::getRoutes()->getByName('Agents')->uri())->toBe('Agents');
        try { Mod::routes(only: ['Inventory']); \Tey\Mod\Tests\Feature\Scaffolds\Support\Examples::testCase()->fail('Duplicate module accepted.'); }
        catch (\LogicException $e) { expect($e->getMessage())->toContain('Inventory', 'P7Test.php:', 'first', 'again'); }
    });
});
