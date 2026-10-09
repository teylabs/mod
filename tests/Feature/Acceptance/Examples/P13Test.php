<?php

use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

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
