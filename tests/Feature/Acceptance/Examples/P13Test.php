<?php

use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Console\Kernel;

it('P13 discovers a portable module template and invokable scaffold', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod/@module/Tools/tool.stub', str_replace('class {{ class }}', '// app template'."\n".'class {{ class }}', TemplateScenario::CLASS_STUB));
        $w->write('app/Modules/Inventory/stubs/mod/@module/Tools/tool.stub', str_replace('class {{ class }}', '// Inventory template'."\n".'class {{ class }}', TemplateScenario::CLASS_STUB));
        $w->write('app/Modules/Knowledge/Tools/.gitkeep', '');
        $w->write('app/Modules/Inventory/Scaffolds/StockReport.php', <<<'PHP'
<?php
namespace App\Modules\Inventory\Scaffolds;
use Tey\Mod\Scaffolds\Scaffold;
final class StockReport
{
    public string $name = 'stock-report';
    public function __invoke(Scaffold $s): void { $s->makes('tool', name: 'Report{name}'); }
}
PHP);
        $w->artisan('mod:tool', ['name' => 'Inventory:CountStock'])->assertSuccessful();
        $w->artisan('mod:tool', ['name' => 'Knowledge:SearchDocuments'])->assertSuccessful();
        $w->artisan('mod:stock-report', ['name' => 'Inventory:Stock'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Tools/CountStock.php'))->toContain('// Inventory template')
            ->and($w->read('app/Modules/Knowledge/Tools/SearchDocuments.php'))->toContain('// app template')
            ->and($w->read('app/Modules/Inventory/Tools/ReportStock.php'))->toContain('// Inventory template')
            ->and(app(ScaffoldRegistry::class)->sources()['stock-report'])->toContain('module:Inventory');
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['templates']['items'], 'source'))->toContain('module:Inventory', 'app')
            ->and($json['scaffolds']['items'][0]['source'])->toBe('module:Inventory');
        $verbose = $w->artisan('mod:list', ['--type' => 'tool', '-v' => true])->assertSuccessful();
        expect($verbose->normalisedOutput())->toContain('Inventory', 'app/Modules/Inventory/stubs/mod/@module/Tools/tool.stub');
    });
});

it('P13 copies Inventory into a fresh testbench app with its template and both scaffold forms', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $source) {
        $class = 'CopiedStockReport'.bin2hex(random_bytes(4));
        $provider = 'InventoryProvider'.bin2hex(random_bytes(4));
        $files = [
            'stubs/mod/@module/Tools/tool.stub' => TemplateScenario::CLASS_STUB,
            'Scaffolds/'.$class.'.php' => '<?php namespace App\\Modules\\Inventory\\Scaffolds; final class '.$class.' { public string $name = "stock-report"; public function __invoke(\\Tey\\Mod\\Scaffolds\\Scaffold $s): void { $s->makes("tool", name: "Report{name}"); } }',
            'Providers/'.$provider.'.php' => '<?php namespace App\\Modules\\Inventory\\Providers; final class '.$provider.' extends \\Illuminate\\Support\\ServiceProvider { public function boot(): void { \\Tey\\Mod\\Facades\\Mod::scaffolds(["stock-count" => fn (\\Tey\\Mod\\Scaffolds\\Scaffold $s) => $s->makes("tool", name: "Count{name}")]); } }',
        ];
        foreach ($files as $relative => $body) {
            $path = $source->path('app/Modules/Inventory/'.$relative);
            app('files')->ensureDirectoryExists(dirname($path));
            file_put_contents($path, $body);
        }
        OwnedAppRoot::using(function (OwnedAppRoot $target) use ($source, $provider) {
            app('files')->copyDirectory($source->path('app/Modules/Inventory'), $target->path('app/Modules/Inventory'));
            file_put_contents($target->path('composer.json'), '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
            require $target->path('app/Modules/Inventory/Providers/'.$provider.'.php');
            TemplateScenario::testCase()->bootApplicationUsing(function (Application $app) use ($target, $provider) {
                if (! $app instanceof \Illuminate\Foundation\Application) {
                    throw new LogicException('Testbench needs a Laravel application.');
                }
                $app->setBasePath($target->path);
                $app->make('config')->set('mod.layout', 'modules');
                $app->register('App\\Modules\\Inventory\\Providers\\'.$provider);
            });
            $kernel = app(Kernel::class);
            expect($kernel->call('mod:tool', ['name' => 'Inventory:CountStock', '--no-interaction' => true]))->toBe(0, $kernel->output());
            expect($kernel->call('mod:stock-report', ['name' => 'Inventory:Stock', '--no-interaction' => true]))->toBe(0, $kernel->output());
            expect($kernel->call('mod:stock-count', ['name' => 'Inventory:Widgets', '--no-interaction' => true]))->toBe(0, $kernel->output());
            foreach (['CountStock', 'ReportStock', 'CountWidgets'] as $name) {
                expect(file_get_contents($target->path('app/Modules/Inventory/Tools/'.$name.'.php')))->toEqualText(TemplateScenario::content('App\\Modules\\Inventory\\Tools', $name));
            }
        });
    });
});
