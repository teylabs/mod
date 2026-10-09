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

it('allows a module to override conflicting package templates without enabling them elsewhere', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['acme/kit', 'beta/kit'] as $package) {
            $w->write('vendor/'.$package.'/stubs/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
            app(StubRegistry::class)->folder($w->root->path('vendor/'.$package.'/stubs'));
        }
        $w->write('app/Modules/Inventory/stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        $w->artisan('mod:tool', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $other = $w->artisan('mod:tool', ['name' => 'Knowledge:Widget'])->assertFailed();
        expect($other->normalisedOutput())->toContain('acme/kit', 'beta/kit')
            ->and($w->exists('app/Modules/Knowledge/Tools/Widget.php'))->toBeFalse();
    });
});

it('allows a module to override conflicting package scaffolds and refuses the ambiguous fallback', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(\Tey\Mod\Scaffolds\ScaffoldRegistry::class);
        foreach (['acme/kit', 'beta/kit'] as $package) {
            $registry->register('report', fn (Scaffold $s) => $s->makes('job', name: 'Package{name}'), $package);
        }
        $registry->register('report', fn (Scaffold $s) => $s->makes('job', name: 'Module{name}'), 'module:Inventory');
        $w->artisan('mod:report', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $other = $w->artisan('mod:report', ['name' => 'Knowledge:Widget'])->assertFailed();
        expect($other->normalisedOutput())->toContain('acme/kit', 'beta/kit')
            ->and($w->exists('app/Modules/Knowledge/Jobs/PackageWidget.php'))->toBeFalse();
    });
});

it('passes module-only template slots through scaffold members and pins the member group', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        $w->write('app/Modules/Inventory/stubs/mod/@module/Tools/[channel]/tool.stub', TemplateScenario::CLASS_STUB);
        Mod::scaffold('tool-report', fn (Scaffold $s) => $s->makes('tool'));
        $preview = json_decode($w->artisan('mod:tool-report', ['name' => 'Inventory:Widget', '--channel' => 'Warehouse', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['files'][0]['group'])->toBe('Inventory');
        $w->artisan('mod:tool-report', ['name' => 'Inventory:Widget', '--channel' => 'Warehouse'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Tools/Warehouse/Widget.php'))->toBeTrue();
    });
});

it('does not replace an unrelated layout recipe when a module owns another scaffold', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(\Tey\Mod\Scaffolds\ScaffoldRegistry::class);
        $registry->register('module-report', fn (Scaffold $s) => $s->makes('job'), 'module:Inventory');
        Mod::layout('modules')->scaffolds('layout-report', fn (Scaffold $s) => $s->makes('job'));
        $w->artisan('mod:layout-report', ['name' => 'Knowledge:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Knowledge/Jobs/Widget.php'))->toBeTrue();
    });
});

it('resolves module tree parts against the same module recipes', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(\Tey\Mod\Scaffolds\ScaffoldRegistry::class);
        $registry->register('child', fn (Scaffold $s) => $s->makes('job', name: 'App{name}'));
        $registry->register('child', fn (Scaffold $s) => $s->makes('job', name: 'Module{name}'), 'module:Inventory');
        $registry->register('parent-report', fn (Scaffold $s) => $s->asks('children', type: 'list', default: ['One'])
            ->each('children', 'child')->part('child', uses: 'child'), 'module:Inventory');
        $w->artisan('mod:parent-report', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/ModuleWidget.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Inventory/Jobs/AppWidget.php'))->toBeFalse();
    });
});

it('uses the package template when another module has the only local override', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('vendor/acme/kit/stubs/@module/Tools/tool.stub', str_replace('class {{ class }}', '// package'."\n".'class {{ class }}', TemplateScenario::CLASS_STUB));
        $w->write('app/Modules/Inventory/stubs/mod/@module/Tools/tool.stub', str_replace('class {{ class }}', '// local'."\n".'class {{ class }}', TemplateScenario::CLASS_STUB));
        app(StubRegistry::class)->folder($w->root->path('vendor/acme/kit/stubs'));
        $w->artisan('mod:tool', ['name' => 'Inventory:Stock'])->assertSuccessful();
        $w->artisan('mod:tool', ['name' => 'Knowledge:Documents'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Tools/Stock.php'))->toContain('// local')
            ->and($w->read('app/Modules/Knowledge/Tools/Documents.php'))->toContain('// package');
        $json = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['templates']['items'], 'source'))->toContain('package:acme/kit', 'module:Inventory');
    });
});

it('refuses a module-only template outside its owner and describes the refusal in a dry run', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        $result = $w->artisan('mod:tool', ['name' => 'Knowledge:Widget'])->assertFailed();
        expect($result->normalisedOutput())->toContain('mod:tool', 'Knowledge', 'stubs/mod/@module/');
        $json = json_decode($w->artisan('mod:tool', ['name' => 'Knowledge:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['would_write'])->toBeFalse()->and($json['files'])->toBe([]);
    });
});

it('selects independent module scaffold questions and restores the fallback between runs', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(\Tey\Mod\Scaffolds\ScaffoldRegistry::class);
        $registry->register('report', fn (Scaffold $s) => $s->asks('title')->makes('job', name: '{title}{name}'), 'module:Inventory');
        $registry->register('report', fn (Scaffold $s) => $s->makes('job', name: 'Package{name}'), 'acme/kit');
        Mod::scaffold('report', fn (Scaffold $s) => $s->makes('job', name: 'App{name}'));
        $w->artisan('mod:report', ['name' => 'Inventory:Stock', '--title' => 'Module'])->assertSuccessful();
        $w->artisan('mod:report', ['name' => 'Knowledge:Stock'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/ModuleStock.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Knowledge/Jobs/AppStock.php'))->toBeTrue();
    });
});

it('uses the module template path and slot grammar instead of the app path', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod/@module/Tools/tool.stub', TemplateScenario::CLASS_STUB);
        $w->write('app/Modules/Inventory/stubs/mod/@module/CustomTools/[channel]/tool.stub', TemplateScenario::CLASS_STUB);
        $w->artisan('mod:tool', ['name' => 'Inventory:Widget', '--channel' => 'Warehouse'])->assertSuccessful();
        $w->artisan('mod:tool', ['name' => 'Knowledge:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/CustomTools/Warehouse/Widget.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Knowledge/Tools/Widget.php'))->toBeTrue();
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
