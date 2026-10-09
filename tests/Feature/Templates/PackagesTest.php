<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Templates\PackageProvider;

it('lets the app template win over packages', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['one', 'two'] as $package) {
            $workspace->write('vendor/acme/'.$package.'/stubs/mod/@group/Package/tool.stub', '// package');
            Mod::stubs()->folder($workspace->root->path('vendor/acme/'.$package.'/stubs/mod'));
        }
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect($result->normalisedOutput())->not->toContain('WARN')
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/Tools/Search.php')))->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content('App\\Modules\\Agents\\Tools', 'Search')));
    });
});

it('disables only a conflicting package command and names both packages', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['one', 'two'] as $package) {
            $workspace->write('vendor/acme/'.$package.'/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
            Mod::stubs()->folder($workspace->root->path('vendor/acme/'.$package.'/stubs/mod'));
        }
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('acme/one', 'acme/two')
            ->and(Artisan::all()['mod:prompt']->isHidden())->toBeTrue()
            ->and($workspace->exists('app/Modules/Agents/Tools/Search.php'))->toBeTrue();
    });
});

it('records the package provider that registered a template folder', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $folder = $workspace->root->path('vendor/acme/agent-kit/stubs/mod');
        $workspace->write('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        (new PackageProvider(app()))->templatesFrom($folder);
        $workspace->artisan('mod:prompt', ['name' => 'Agents:Answer'])->assertSuccessful();
        expect(app(TemplateCatalog::class)->templates()['prompt']['source'])->toBe(PackageProvider::class);
        $workspace->artisan('mod:list', ['--type' => 'prompt'])->assertSuccessful()->expectsOutputToContain('template ('.PackageProvider::class.')');
        $data = json_decode($workspace->artisan('mod:list', ['--json' => true, '--type' => 'prompt'])->assertSuccessful()->normalisedOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['types'][0]['source'])->toBe('template ('.PackageProvider::class.')');
    });
});

it('explains a disabled template when invoked directly and writes nothing', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['one', 'two'] as $package) {
            $workspace->write('vendor/acme/'.$package.'/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
            Mod::stubs()->folder($workspace->root->path('vendor/acme/'.$package.'/stubs/mod'));
        }
        $workspace->artisan('mod:prompt', ['name' => 'Agents:Blocked'])->assertFailed()
            ->expectsOutputToContain('acme/one')->expectsOutputToContain('acme/two')
            ->expectsOutputToContain('Define the template in the app');
        expect($workspace->exists('app/Modules/Agents/Prompts/Blocked.php'))->toBeFalse();
    });
});

it('lists the packages replaced by an app template in human and json output', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['one', 'two'] as $package) {
            $workspace->write('vendor/acme/'.$package.'/stubs/mod/@group/Package/tool.stub', TemplateScenario::CLASS_STUB);
            Mod::stubs()->folder($workspace->root->path('vendor/acme/'.$package.'/stubs/mod'));
        }
        $source = 'template (app (overrides acme/one, acme/two))';
        $workspace->artisan('mod:list', ['--type' => 'tool'])->assertSuccessful()->expectsOutputToContain($source);
        $data = json_decode($workspace->artisan('mod:list', ['--json' => true, '--type' => 'tool'])->assertSuccessful()->normalisedOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['types'][0]['source'])->toBe($source);
    });
});

it('scans package provider parent segments with portable separators', function (bool $windows) {
    Workspace::run(null, function (Workspace $workspace) use ($windows) {
        TemplateScenario::tool($workspace);
        $workspace->write('vendor/acme/agent-kit/stubs/mod/@group/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        $workspace->write('vendor/acme/agent-kit/src/KitProvider.php', <<<'PHP'
<?php
use Tey\Mod\Facades\Mod;

return new class(app()) extends \Illuminate\Support\ServiceProvider {
    public function boot(): void
    {
        Mod::stubs()->folder(__DIR__.'/../stubs/mod');
    }
};
PHP);
        $provider = require $workspace->root->path('vendor/acme/agent-kit/src/KitProvider.php');
        if ($windows) {
            (new PackageProvider(app()))->templatesFrom(str_replace('/', '\\', $workspace->root->path('vendor/acme/agent-kit/src/./../stubs/mod')));
        } else {
            $provider->boot();
        }
        $workspace->artisan('mod:prompt', ['name' => 'Agents:Answer'])->assertSuccessful();
        expect(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/Prompts/Answer.php')))
            ->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content('App\\Modules\\Agents\\Prompts', 'Answer')));
    });
})->with([false, true]);
