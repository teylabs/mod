<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('selects module app and package templates by the target module without leaking between runs', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['vendor/acme/kit/stubs', 'stubs/mod', 'app/Modules/Inventory/stubs/mod'] as $index => $folder) {
            $w->write($folder.'/@module/Tools/tool.stub', str_replace('class {{ class }}', '// source '.$index."\n".'class {{ class }}', TemplateScenario::CLASS_STUB));
        }
        app(StubRegistry::class)->folder($w->root->path('vendor/acme/kit/stubs'));
        $w->write('app/Modules/Knowledge/Tools/.gitkeep', '');
        $w->artisan('mod:tool', ['name' => 'Inventory:First'])->assertSuccessful();
        $w->artisan('mod:tool', ['name' => 'Knowledge:Second'])->assertSuccessful();
        $w->artisan('mod:tool', ['name' => 'Inventory:Third'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Tools/First.php'))->toContain('// source 2')
            ->and($w->read('app/Modules/Knowledge/Tools/Second.php'))->toContain('// source 1')
            ->and($w->read('app/Modules/Inventory/Tools/Third.php'))->toContain('// source 2');
    });
});

it('skips a module template outside the module anchor with a warning', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/stubs/mod/resources/js/unsafe.ts.stub', 'export const unsafe = true;');
        $result = $w->artisan('mod:list', ['--json' => true])->assertSuccessful();
        $json = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['templates']['problems'])->toHaveCount(1)
            ->and($json['templates']['problems'][0]['reason'])->toContain('@module');
    });
});

it('attributes a provider scaffold by namespace and retains app registrations separately', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $class = 'Provider'.bin2hex(random_bytes(4));
        $w->write('app/Modules/Inventory/Providers/'.$class.'.php', '<?php namespace App\\Modules\\Inventory\\Providers; class '.$class.' extends \\Illuminate\\Support\\ServiceProvider { public function boot(): void { \\Tey\\Mod\\Facades\\Mod::scaffolds(["stock-count" => fn (\\Tey\\Mod\\Scaffolds\\Scaffold $s) => $s->makes("job", name: "Module{name}")]); } }');
        require $w->root->path('app/Modules/Inventory/Providers/'.$class.'.php');
        $provider = 'App\\Modules\\Inventory\\Providers\\'.$class;
        (new $provider(app()))->boot();
        Mod::scaffold('stock-count', fn (Scaffold $s) => $s->makes('job', name: 'App{name}'));
        $w->artisan('mod:stock-count', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $w->artisan('mod:stock-count', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/ModuleWidget.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Knowledge/Jobs/AppDocument.php'))->toBeTrue();
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['scaffolds']['items'], 'source'))->toContain('module:Inventory', 'app');
    });
});
