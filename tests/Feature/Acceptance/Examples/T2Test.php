<?php

use Illuminate\Support\Composer;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Tey\Mod\Discovery\DiscoveredArtifact;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

beforeEach(function () {
    putenv('COLUMNS=72');
});

afterEach(function () {
    putenv('COLUMNS');
});

it('T2 F3 fills the original anchor and neutral group with name forms after remapping', function (string $anchor) {
    Workspace::run(null, function (Workspace $w) use ($anchor) {
        Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
        config()->set('mod.layout', 'areas');
        $w->write('stubs/mod/@'.$anchor.'/Tools/tool.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }}\n{\n    public const VALUES = '{{ {$anchor} }}|{{ {$anchor}.kebab }}|{{ group }}|{{ group.snake }}|{{ area }}|{{ class.camel }}';\n}\n");
        $result = $w->artisan('mod:tool', ['name' => 'BillingOps:Search', '--no-interaction' => true])->assertSuccessful();
        expect($w->files())->toBe(['src/Areas/BillingOps/Tools/Search.php', 'stubs/mod/@'.$anchor.'/Tools/tool.stub'])
            ->and(TemplateScenario::normalise($w, $w->read('src/Areas/BillingOps/Tools/Search.php')))
            ->toBe("<?php\nnamespace Areas\\BillingOps\\Tools;\nclass Search\n{\n    public const VALUES = 'BillingOps|billing-ops|BillingOps|billing_ops|BillingOps|search';\n}\n")
            ->and($result->normalisedOutput())->not->toContain('unresolved');
        if ($anchor === 'module') {
            $w->artisan('mod:list')->assertSuccessful()->expectsOutputToContain('Template anchor @module resolves to @area');
        }
    });
})->with(['module', 'area', 'group']);

it('T2 F3 names every unresolved placeholder after rendering', function () {
    Workspace::run(null, function (Workspace $w) {
        TemplateScenario::tool($w);
        $w->write('stubs/mod/@module/Tools/tool.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }}\n{\n    // {{ missing }} {{ module.unknown }} {{ other }} {{ missing }}\n}\n");
        $result = $w->artisan('mod:tool', ['name' => 'Knowledge:Search'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   WARN  mod:tool has unresolved template placeholders: {{ missing }}, {{ module.unknown }}, {{ other }}. Check the template body.  \n\n   INFO  Tool [app/Modules/Knowledge/Tools/Search.php] created successfully.  \n\n")
            ->and(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Tools/Search.php')))
            ->toBe("<?php\nnamespace App\\Modules\\Knowledge\\Tools;\nclass Search\n{\n    // {{ missing }} {{ module.unknown }} {{ other }} {{ missing }}\n}\n");
    });
});

it('T2 F4 counts factories and policies without expanding default discovery and shows their targets for models', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.discovery.enabled', true);
        $discovery = new Discovery(app(CompiledLayout::class), new DiscoveryOptions, $w->root->path);
        $model = 'App\\Modules\\Billing\\Models\\Invoice';
        $inventory = new Inventory([
            new DiscoveredArtifact('model', DiscoveryType::Factory, $model, 'app/Modules/Billing/Models/Invoice.php', target: 'App\\Modules\\Billing\\Factories\\InvoiceFactory'),
            new DiscoveredArtifact('model', DiscoveryType::Policy, $model, 'app/Modules/Billing/Models/Invoice.php', target: 'App\\Modules\\Billing\\Policies\\InvoicePolicy'),
        ]);
        $discovery->cache()->write($inventory, $discovery->presetFingerprint(), $discovery->definitionsFingerprint());
        app()->instance(Discovery::class, $discovery);
        $w->artisan('mod:list')->assertSuccessful()
            ->expectsOutputToContain('0 providers, 0 commands, 0 listeners, 0 subscribers, 1 factories, 1 policies, 0 directories, 0 rejected.')
            ->doesntExpectOutputToContain('InvoiceFactory')->doesntExpectOutputToContain('InvoicePolicy');
        $w->artisan('mod:list', ['--type' => 'model'])->assertSuccessful()
            ->expectsOutputToContain('factory: '.$model.' -> App\\Modules\\Billing\\Factories\\InvoiceFactory [app/Modules/Billing/Models/Invoice.php]')
            ->expectsOutputToContain('policy: '.$model.' -> App\\Modules\\Billing\\Policies\\InvoicePolicy [app/Modules/Billing/Models/Invoice.php]');
    });
});

it('T2 F5 preserves mappings and gives recovery guidance for every failed Composer dump', function (string $failure) {
    Workspace::run(null, function (Workspace $w) use ($failure) {
        config()->set('mod.layout', 'ddd');
        $composer = Mockery::mock(Composer::class);
        $composer->shouldReceive('setWorkingPath')->once()->with($w->root->path)->andReturnSelf();
        $dump = $composer->shouldReceive('dumpAutoloads')->once();
        if ($failure === 'signal') {
            $process = new class(['composer', 'dump-autoload']) extends Process
            {
                public function getTermSignal(): int
                {
                    return 6;
                }
            };
            $dump->andThrow(new ProcessSignaledException($process));
        } elseif ($failure === 'start') {
            $process = new Process(['composer', 'dump-autoload'], $w->root->path);
            $dump->andThrow(new ProcessStartFailedException($process, 'failed to start'));
        } else {
            $dump->andReturn((int) $failure);
        }
        app()->instance(Composer::class, $composer);
        $result = $w->artisan('mod:autoload')->assertFailed();
        expect($result->exitCode)->toBe(1);
        $result->expectsOutputToContain('composer.json was updated; run composer dump-autoload');
        expect(json_decode($w->read('composer.json'), true)['autoload']['psr-4'])->toHaveKey('Domain\\');
        $w->artisan('mod:autoload')->assertSuccessful()
            ->expectsOutputToContain('Every root of the ddd layout has its Composer mapping configured.')
            ->expectsOutputToContain('If classes do not load, run composer dump-autoload.')
            ->doesntExpectOutputToContain('is autoloaded');
    });
})->with(['1', '126', '127', '9009', 'signal', 'start']);
