<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('scans literal bracket folders and keeps neighbouring files separate', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/[ab]/bracket.stub', TemplateScenario::CLASS_STUB);
        $workspace->write('stubs/mod/@module/a/literal.stub', TemplateScenario::CLASS_STUB);
        $workspace->artisan('mod:bracket', ['name' => 'Agents:One', '--ab' => 'B'])->assertSuccessful();
        $workspace->artisan('mod:literal', ['name' => 'Agents:Two'])->assertSuccessful();
        expect($workspace->exists('app/Modules/Agents/B/One.php'))->toBeTrue()
            ->and($workspace->exists('app/Modules/Agents/a/Two.php'))->toBeTrue();
    });
});

it('disables both app templates whose file names normalize to one command', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['show-page', 'ShowPage'] as $file) {
            $workspace->write('stubs/mod/@module/Pages/'.$file.'.stub', TemplateScenario::CLASS_STUB);
        }
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect(Artisan::all())->not->toHaveKey('mod:show-page')
            ->and($result->normalisedOutput())->toContain('show-page.stub', 'ShowPage.stub')
            ->and(app(TemplateCatalog::class)->skipped())->toHaveCount(2);
    });
});

it('makes templates and PHP declarations compile to equivalent file types', function (bool $refine) {
    Workspace::run(null, function (Workspace $workspace) use ($refine) {
        TemplateScenario::tool($workspace);
        if ($refine) {
            Mod::layout('modules')->generates('tool', suffix: 'Tool', aliases: ['mod:utility'], label: 'Utility');
        }
        $actual = app(CompiledLayout::class);
        $registry = new LayoutRegistry;
        $registry->layout('modules')->generates('tool', in: 'Modules/{module}/Tools', suffix: $refine ? 'Tool' : null, aliases: $refine ? ['mod:utility'] : [], stub: Stub::file($workspace->root->path('stubs/mod/@module/Tools/tool.stub')), label: $refine ? 'Utility' : null);
        $expected = $registry->compile('modules');
        expect(place($actual, 'tool', 'Search', 'Agents')->path())->toBe(place($expected, 'tool', 'Search', 'Agents')->path())
            ->and($actual->kind('tool')->namePolicy)->toEqual($expected->kind('tool')->namePolicy);
    });
})->with([false, true]);

it('supports roots declared only in Composer and rejects plain-file roots safely', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Acme\\' => 'lib/']]], JSON_THROW_ON_ERROR));
        Mod::layout('modules')->mounts('plain', null, 'notes');
        $workspace->write('stubs/mod/lib/Tools/extra.stub', TemplateScenario::CLASS_STUB);
        $workspace->write('stubs/mod/notes/invalid.stub', TemplateScenario::CLASS_STUB);
        $workspace->artisan('mod:extra', ['name' => 'Search'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $workspace->read('lib/Tools/Search.php')))->toBe(TemplateScenario::content('Acme\\Tools', 'Search'))
            ->and(app(TemplateCatalog::class)->skipped()['stubs/mod/notes/invalid.stub'])->toContain("plain-file templates aren't supported yet");
    });
});
