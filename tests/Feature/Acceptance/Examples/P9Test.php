<?php

use Illuminate\Support\Facades\Route;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P9 skips files already loaded by this apps provider and cached calls do nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Knowledge/routes/web.php', "<?php config()->push('route_trace', 'provider');");
        config()->set('route_trace', []);
        $provider = new class(app()) extends \Illuminate\Support\ServiceProvider {
            public function boot(): void { $this->loadRoutesFrom(app()->basePath('app/Modules/Knowledge/routes/web.php')); }
        };
        app()->register($provider);
        Mod::routes();
        expect(config('route_trace'))->toBe(['provider']);
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['routes'][0]['loaded_by'])->toStartWith('provider: ');
        $w->write('bootstrap/cache/routes-v7.php', '<?php');
        app()->forgetInstance('routes.cached');
        Mod::routes();
        expect(config('route_trace'))->toBe(['provider']);
    });
});
