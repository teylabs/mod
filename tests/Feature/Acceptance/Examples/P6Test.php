<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P6 generates exact route files and loads them only through the explicit call', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $result = $w->artisan('mod:routes', ['module' => 'Inventory', '--api' => true])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   INFO  Route file [app/Modules/Inventory/routes/web.php] created successfully.  \n\n\n   INFO  Route file [app/Modules/Inventory/routes/api.php] created successfully.  \n\n");
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Inventory/routes/web.php')))->toBe("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n// mod:routes\n");
        $w->write('app/Modules/Inventory/routes/web.php', "<?php \Illuminate\Support\Facades\Route::get('widgets', fn () => 'widgets')->name('widgets.index');");
        $w->write('app/Modules/Inventory/routes/api.php', "<?php \Illuminate\Support\Facades\Route::get('widgets', fn () => 'api')->name('api.widgets');");
        expect(Route::getRoutes()->getByName('widgets.index'))->toBeNull();
        Mod::routes();
        Route::getRoutes()->refreshNameLookups();
        expect(Route::getRoutes()->getByName('widgets.index')->uri())->toBe('widgets')
            ->and(Route::getRoutes()->getByName('api.widgets')->uri())->toBe('api/widgets');
    });
});
