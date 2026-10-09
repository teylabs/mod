<?php

use Composer\Autoload\ClassLoader;
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

it('keeps slots out of native group placement and accepts nested domains with slot options', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::webhook($workspace);
        $result = $workspace->artisan('mod:model', ['name' => 'Knowledge/Drive:FileChanged'])->assertFailed();
        expect($result->normalisedOutput())->toContain("Modules don't nest.")
            ->and(app(CompiledLayout::class)->dimensionNames())->toBe(['module']);
    });
});

it('skips a template whose command clashes with a native alias', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        Mod::layout('modules')->generates('model', aliases: ['mod:record']);
        $workspace->write('stubs/mod/@module/Records/record.stub', TemplateScenario::CLASS_STUB);
        $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect(app(TemplateCatalog::class)->skipped()['stubs/mod/@module/Records/record.stub'])->toContain('mod:record already exists');
    });
});

it('skips template commands whose dash-free aliases collide', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        foreach (['show-page', 'showpage'] as $file) {
            $workspace->write('stubs/mod/@module/Pages/'.$file.'.stub', TemplateScenario::CLASS_STUB);
        }
        $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect(Artisan::all())->not->toHaveKey('mod:show-page');
        expect(Artisan::all())->not->toHaveKey('mod:showpage');
    });
});

it('generates the fixture tree across every grouped layout and plain Laravel', function (string $layout, string $path, string $name, string $output, string $namespace) {
    Workspace::run(null, function (Workspace $workspace) use ($layout, $path, $name, $output, $namespace) {
        config()->set('mod.layout', $layout);
        $workspace->write('stubs/mod/'.$path.'/tool.stub', TemplateScenario::CLASS_STUB);
        mkdir($workspace->root->path(dirname($output)), 0700, true);
        $loader = new ClassLoader;
        $loader->addPsr4('Domain\\', $workspace->root->path('src/Domain'));
        $loader->register();
        try {
            $workspace->artisan('mod:tool', ['name' => $name])->assertSuccessful();
            expect(str_replace("\r\n", "\n", $workspace->read($output)))->toBe(TemplateScenario::content($namespace, 'Search'));
        } finally {
            $loader->unregister();
        }
    });
})->with([
    ['modules', '@module/Tools', 'Agents:Search', 'app/Modules/Agents/Tools/Search.php', 'App\\Modules\\Agents\\Tools'],
    ['features', '@feature/Tools', 'Agents:Search', 'app/Features/Agents/Tools/Search.php', 'App\\Features\\Agents\\Tools'],
    ['ddd', '@domain/Tools', 'Agents/Chat:Search', 'src/Domain/Agents/Chat/Tools/Search.php', 'Domain\\Agents\\Chat\\Tools'],
    ['slices', '@slice/Tools', 'Agents/Chat:Search', 'app/Agents/Chat/Tools/Search.php', 'App\\Agents\\Chat\\Tools'],
    ['type-first', '@feature/Tools', 'Agents:Search', 'app/Tools/Agents/Search.php', 'App\\Tools\\Agents'],
    ['laravel', 'Tools', 'Search', 'app/Tools/Search.php', 'App\\Tools'],
]);

it('keeps an HTTP request working with a broken template', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@modul/Broken/broken.stub', TemplateScenario::CLASS_STUB);
        app(CompiledLayout::class);
        app('router')->get('/template-health', fn (): string => 'ok');
        TemplateScenario::testCase()->get('/template-health')->assertOk()->assertContent('ok');
        expect(app(TemplateCatalog::class)->skipped())->toHaveCount(1);
    });
});

it('fills the app namespace and every name form in a generated file', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/Tools/tool.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { const FORMS = '{{ class.camel }}|{{ class.kebab }}|{{ class.snake }}|{{ class.studly }}|{{ class.plural }}|{{ rootNamespace }}'; }\n");
        $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $workspace->read('app/Modules/Agents/Tools/SearchDocuments.php')))->toBe("<?php\nnamespace App\\Modules\\Agents\\Tools;\nclass SearchDocuments { const FORMS = 'searchDocuments|search-documents|search_documents|SearchDocuments|SearchDocuments|App\\'; }\n");
    });
});

it('keeps DTO base selection behind an edited template', function (bool $installed) {
    Workspace::run(null, function (Workspace $workspace) use ($installed) {
        TemplateScenario::tool($workspace);
        starterPackages(...($installed ? ['spatie/laravel-data'] : []));
        $workspace->write('stubs/mod/@module/Data/links.stub', "<?php\n\nnamespace {{ namespace }};\n{{ baseImport }}\nclass {{ class }}{{ extends }}\n{\n    public const EDITED = true;\n}\n");
        Mod::layout('modules')->generates('links', suffix: 'Links');
        $workspace->artisan('mod:links', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $base = $installed ? 'Spatie\\LaravelData\\Data' : 'App\\Support\\Data\\DataTransferObject';
        expect(str_replace("\r\n", "\n", $workspace->read('app/Modules/Knowledge/Data/DocumentLinks.php')))
            ->toBe("<?php\n\nnamespace App\\Modules\\Knowledge\\Data;\n\nuse {$base};\n\nclass DocumentLinks extends ".class_basename($base)."\n{\n    public const EDITED = true;\n}\n");
        if (! $installed) {
            expect($workspace->exists('app/Support/Data/DataTransferObject.php'))->toBeTrue();
        }
    });
})->with([false, true]);

it('uses a template refinement alias and label', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        Mod::layout('modules')->generates('tool', aliases: ['mod:utility'], label: 'Utility', fixed: 'Search');
        $result = $workspace->artisan('mod:utility', ['name' => 'Agents:'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   INFO  Utility [app/Modules/Agents/Tools/Search.php] created successfully.  \n\n")
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Agents/Tools/Search.php')))->toBe(TemplateScenario::content('App\\Modules\\Agents\\Tools', 'Search'));
    });
});

it('reports a mismatched anchor only once per application', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@domain/Prompts/prompt.stub', TemplateScenario::CLASS_STUB);
        $first = $workspace->artisan('mod:prompt', ['name' => 'Agents:Answer'])->assertSuccessful();
        $second = $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect($first->normalisedOutput())->toContain('Template anchor @domain resolves to @module')
            ->and($second->normalisedOutput())->not->toContain('Template anchor');
    });
});

it('keeps invalid literal folders and their refinements from breaking other commands', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/Bad*/broken.stub', TemplateScenario::CLASS_STUB);
        $workspace->write('stubs/mod/{module}/Tools/refined.stub', TemplateScenario::CLASS_STUB);
        Mod::layout('modules')->generates('refined', suffix: 'Refined');
        $workspace->artisan('mod:tool', ['name' => 'Agents:Search'])->assertSuccessful();
        expect(app(TemplateCatalog::class)->skipped())->toHaveCount(2);
        expect(Artisan::all())->not->toHaveKey('mod:broken');
        expect(Artisan::all())->not->toHaveKey('mod:refined');
    });
});
