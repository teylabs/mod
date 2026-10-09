<?php

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('does not mistake a previous apps included file for provider loading in the next app', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('app/Modules/Inventory/routes/web.php', "<?php config()->push('route_trace', 'web');");
        config()->set('mod.layout', 'modules');
        config()->set('route_trace', []);
        Mod::routes();
        expect(config('route_trace'))->toBe(['web']);
        Examples::testCase()->bootApplicationUsing(function (Illuminate\Contracts\Foundation\Application $app) use ($w) {
            if (! $app instanceof Application) {
                throw new LogicException('Routing tests require Laravel.');
            }
            $app->setBasePath($w->root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('route_trace', []);
        });
        Mod::routes();
        expect(config('route_trace'))->toBe(['web']);
    });
});

it('loads console files only while running in the console', function (bool $console) {
    Workspace::run(null, function (Workspace $w) use ($console) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/routes/console.php', "<?php config()->push('route_trace', 'console');");
        config()->set('route_trace', []);
        (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), $console);
        Mod::routes();
        expect(config('route_trace'))->toBe($console ? ['console'] : []);
    });
})->with([true, false]);

it('caches real module routes and preserves route list after a cached boot', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('bootstrap/providers.php', '<?php return [\\Tey\\Mod\\ModServiceProvider::class];');
        $w->write('config/mod.php', "<?php return ['layout' => 'modules'];");
        $w->write('app/Modules/Inventory/routes/web.php', "<?php \\Illuminate\\Support\\Facades\\Route::get('widgets', [\\Illuminate\\Routing\\RedirectController::class, '__invoke'])->name('widgets.index');");
        $w->write('app/Modules/Inventory/routes/api.php', "<?php \\Illuminate\\Support\\Facades\\Route::get('widgets', [\\Illuminate\\Routing\\RedirectController::class, '__invoke'])->name('api.widgets');");
        $w->write('bootstrap/app.php', <<<'APP'
<?php
return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(then: fn () => \Tey\Mod\Facades\Mod::routes())
    ->create();
APP);
        $script = <<<'SCRIPT'
require $argv[1];
$app = require $argv[2].'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('route:list', ['--json' => true, '--path' => 'widgets']);
echo $kernel->output();
if ($argv[3] === 'cache') {
    $kernel->call('route:cache');
    fwrite(STDERR, $kernel->output());
} else {
    if (! $app->routesAreCached()) { throw new \RuntimeException('Routes are not cached.'); }
    \Tey\Mod\Facades\Mod::routes();
}
SCRIPT;
        $arguments = [PHP_BINARY, '-r', $script, dirname(__DIR__, 3).'/vendor/autoload.php', $w->root->path];
        $before = new Process([...$arguments, 'cache'], $w->root->path);
        $before->mustRun();
        expect($before->getErrorOutput())->toContain('Routes cached successfully.');
        $w->write('app/Modules/Inventory/routes/web.php', "<?php throw new \\RuntimeException('cached route file was loaded');");
        $after = new Process([...$arguments, 'cached'], $w->root->path);
        $after->mustRun();
        expect(json_decode($after->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(json_decode($before->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        expect(array_column(json_decode($after->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'uri'))->toContain('widgets', 'api/widgets');
    });
});

it('resolves alphabetical and configured order within each filtered call', function (array $order, array $first, array $rest) {
    Workspace::run(null, function (Workspace $w) use ($order, $first, $rest) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.routes.order', $order);
        foreach (['Inventory', 'Knowledge', 'Billing', 'Agents'] as $group) {
            $w->write("app/Modules/{$group}/routes/web.php", "<?php config()->push('route_trace', '{$group}');");
        }
        config()->set('route_trace', []);
        Mod::routes(only: ['Knowledge', 'Billing']);
        expect(config('route_trace'))->toBe($first);
        Mod::routes(except: ['Knowledge', 'Billing']);
        expect(config('route_trace'))->toBe([...$first, ...$rest]);
    });
})->with([
    [[], ['Billing', 'Knowledge'], ['Agents', 'Inventory']],
    [['Knowledge', 'Inventory'], ['Knowledge', 'Billing'], ['Inventory', 'Agents']],
]);

it('groups registrar routes with the same Laravel web and API defaults as files', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $namespace = 'RegistrarDefaults'.bin2hex(random_bytes(4));
        $w->write('app/Modules/Inventory/Http/Routing/Unconventional.php', "<?php namespace {$namespace}; class Unconventional implements \\Tey\\Mod\\Routing\\RegistersRoutes { public static function web(): void { \\Illuminate\\Support\\Facades\\Route::get('widgets', fn () => 'web')->name('widgets'); } public static function api(): void { \\Illuminate\\Support\\Facades\\Route::get('widgets', fn () => 'api')->name('api.widgets'); } }");
        Route::prefix('admin')->name('admin.')->middleware('auth')->group(fn () => Mod::routes());
        $routes = app(Router::class)->getRoutes();
        $routes->refreshNameLookups();
        expect($routes->getByName('admin.widgets')->uri())->toBe('admin/widgets')
            ->and($routes->getByName('admin.widgets')->middleware())->toBe(['auth', 'web'])
            ->and($routes->getByName('admin.api.widgets')->uri())->toBe('admin/api/widgets')
            ->and($routes->getByName('admin.api.widgets')->middleware())->toBe(['auth', 'api']);
        $data = json_decode($w->artisan('route:list', ['--path' => 'widgets', '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($data, 'uri'))->toBe(['admin/api/widgets', 'admin/widgets']);
    });
});
