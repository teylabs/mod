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
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Agents/Tools/Search.php')))->toBe(TemplateScenario::content('App\\Modules\\Agents\\Tools', 'Search'));
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
            ->and(Artisan::all())->not->toHaveKey('mod:prompt')
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
    });
});
