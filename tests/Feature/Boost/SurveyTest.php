<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tey\Mod\Boost\SurveyTool;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Survey\Scope;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Boost\SurveyFixture;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;

it('reports names carried in page folders and declared or render-inferred entry stems', function (bool $declared) {
    Workspace::run(null, function (Workspace $w) use ($declared) {
        config()->set('mod.layout', 'laravel');
        if ($declared) {
            Mod::layout('laravel')->frontend(pageEntries: ['Page', 'Modal']);
        }
        foreach (['Widget', 'Gadget'] as $name) {
            foreach (['Edit', 'Show'] as $action) {
                $w->write("resources/js/pages/{$name}/{$action}/Page.vue", '<template />');
            }
            $w->write("resources/js/pages/{$name}/Layout.vue", '<template />');
            $w->write("resources/js/pages/{$name}/Edit/Partials/Form.vue", '<template />');
            $w->write("resources/js/pages/{$name}/Partials/Toolbar.ts", 'export {};');
        }
        $w->write('routes/web.php', "<?php use Inertia\\Inertia; Inertia::render('Widget/Edit/Page'); Inertia::render('Gadget/Show/Page');");
        $before = Scenario::bytes($w);
        $tool = app(SurveyTool::class);
        $response = $tool->handle(new Request);
        $data = Scenario::data($response, $tool);
        expect($data['page_patterns'])->toContain([
            'files' => [
                'resources/js/pages/Gadget/Edit/Page.vue', 'resources/js/pages/Gadget/Show/Page.vue',
                'resources/js/pages/Widget/Edit/Page.vue', 'resources/js/pages/Widget/Show/Page.vue',
            ], 'pattern' => '{name}/{action}/Page.vue', 'root' => 'resources/js/pages',
        ], [
            'files' => ['resources/js/pages/Gadget/Layout.vue', 'resources/js/pages/Widget/Layout.vue'],
            'pattern' => '{name}/Layout.vue', 'root' => 'resources/js/pages',
        ], [
            'files' => [
                'resources/js/pages/Gadget/Edit/Partials/Form.vue', 'resources/js/pages/Gadget/Partials/Toolbar.ts',
                'resources/js/pages/Widget/Edit/Partials/Form.vue', 'resources/js/pages/Widget/Partials/Toolbar.ts',
            ], 'pattern' => '{name}/**/Partials/*', 'root' => 'resources/js/pages',
        ])->and($data['page_entries'])->toBe([[
            'root' => 'resources/js/pages', 'source' => $declared ? 'declared' : 'inferred',
            'stems' => $declared ? ['Modal', 'Page'] : ['Page'],
        ]])->and((string) $tool->handle(new Request)->content())->toBe((string) $response->content())
            ->and(Scenario::bytes($w))->toBe($before);
    });
})->with([false, true]);

it('keeps grouped and mounted page entry evidence separate', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->allowsNesting()->frontend(pageEntries: ['Page'])
            ->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(pages: 'pages', pageName: 'UI::{path}', pageEntries: ['Modal']));
        $w->write('app/Modules/Team/Reports/resources/js/pages/Widget/Edit/Page.vue', '<template />');
        $w->write('app/UI/pages/Widget/Edit/Modal.vue', '<template />');
        $w->write('routes/web.php', "<?php use Inertia\\Inertia; Inertia::render('UI::Widget/Edit/Modal');");
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect($data['page_entries'])->toBe([
            ['root' => 'app/Modules/Team/Reports/resources/js/pages', 'source' => 'declared', 'stems' => ['Page']],
            ['root' => 'app/UI/pages', 'source' => 'declared', 'stems' => ['Modal']],
        ]);
    });
});

it('returns the complete expected fixture JSON without executing source or changing files', function () {
    Workspace::run([
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'kinds' => [
            'model' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Models']],
            'task' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Tasks']],
        ],
    ], function (Workspace $w) {
        config()->set('mod.layout', 'fixture');
        $w->write('app/Models/Zebra.php', '<?php namespace Actual; class Zebra {} class Companion {} throw new \\RuntimeException("must not execute");');
        $w->write('app/Models/Ant.php', '<?php namespace App\\Models; class Ant {}');
        $w->write('notes.txt', 'PRIVATE_BODY_MUST_NOT_APPEAR');
        $before = Scenario::bytes($w);
        $tool = app(SurveyTool::class);
        $response = $tool->handle(new Request);
        $data = Scenario::data($response, $tool);
        $expected = json_decode(file_get_contents(__DIR__.'/../../Fixtures/survey.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($data)->toBe($expected)
            ->and((string) $tool->handle(new Request)->content())->toBe((string) $response->content())
            ->and(Scenario::bytes($w))->toBe($before)
            ->and(class_exists('Actual\\Zebra', false))->toBeFalse();
    });
});

it('surveys all types and joins imports pages registered routes and policies across folders', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', null);
        foreach (['Widget', 'Gadget'] as $name) {
            $w->write("app/Models/{$name}.php", "<?php namespace App\\Models; class {$name} {}");
            $w->write("app/Http/Requests/Store{$name}Request.php", "<?php namespace App\\Http\\Requests; class Store{$name}Request {}");
            $w->write("app/Policies/{$name}Policy.php", "<?php namespace App\\Policies; class {$name}Policy {}");
            $w->write("database/factories/{$name}Factory.php", "<?php namespace Database\\Factories; use App\\Models\\{$name}; class {$name}Factory {}");
            $w->write("resources/js/pages/{$name}/Show.vue", '<template>PRIVATE_PAGE_BODY</template>');
            $w->write("app/Http/Controllers/{$name}Controller.php", str_replace('Widget', $name, <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\{Models\Widget as Record, Http\Requests\StoreWidgetRequest, function Models\helper, const Models\FLAG};
use Inertia\Inertia as Screen, Vendor\Package\Service;
// use App\Models\Fake;
class WidgetController {
    public function show() {
        Screen::render('Widget/Show');
        Screen::render('NotLiteral/' . $name);
        Screen::render($name);
        $closure = function () use ($name) {};
    }
}
PHP));
        }
        $w->write('app/Policies/SpecialPolicy.php', '<?php namespace App\\Policies; class SpecialPolicy {}');
        Gate::policy('App\\Models\\Gadget', 'App\\Policies\\SpecialPolicy');
        Route::get('widgets/{widget}', 'App\\Http\\Controllers\\WidgetController@show');
        $w->write('routes/web.php', '<?php use App\\Http\\Controllers\\WidgetController;');
        $w->write('database/migrations/2026_01_01_000000_seed_widgets.php', '<?php use App\\Models\\Widget; return new class {};');
        $w->write('vendor/example/package/Service.php', '<?php throw new \\RuntimeException("vendor must not execute");');
        $loader = new ClassLoader;
        $loader->addPsr4('Vendor\\Package\\', $w->root->path('vendor/example/package'));
        $loader->register();
        try {
            $tool = app(SurveyTool::class);
            $data = Scenario::data($tool->handle(new Request), $tool);
            $types = array_column($data['types'], null, 'id');
            expect($data['layout'])->toBe('laravel')
                ->and($types['request']['groups'][0]['classes'])->toBe(['App\\Http\\Requests\\StoreGadgetRequest', 'App\\Http\\Requests\\StoreWidgetRequest'])
                ->and($types['model']['groups'][0]['classes'])->toBe(['App\\Models\\Gadget', 'App\\Models\\Widget'])
                ->and($data['references'])->toContain(
                    ['from' => 'App\\Http\\Controllers\\WidgetController', 'kind' => 'import', 'to' => 'App\\Models\\Widget'],
                    ['from' => 'App\\Http\\Controllers\\WidgetController', 'kind' => 'page', 'to' => 'page:Widget/Show'],
                    ['from' => 'route:GET|HEAD widgets/{widget}', 'kind' => 'route', 'to' => 'App\\Http\\Controllers\\WidgetController'],
                    ['from' => 'App\\Models\\Widget', 'kind' => 'policy', 'to' => 'App\\Policies\\WidgetPolicy'],
                    ['from' => 'App\\Policies\\SpecialPolicy', 'kind' => 'model', 'to' => 'App\\Models\\Gadget'],
                )
                ->and(json_encode($data))->not->toContain('Fake', 'helper', 'FLAG', 'NotLiteral', 'PRIVATE_PAGE_BODY')
                ->and($data['vendor'])->toBe([[
                    'class' => 'Vendor\\Package\\Service', 'path' => 'vendor/example/package/Service.php',
                    'used_by' => ['App\\Http\\Controllers\\GadgetController', 'App\\Http\\Controllers\\WidgetController', 'file:app/Http/Controllers/GadgetController.php', 'file:app/Http/Controllers/WidgetController.php'],
                ]]);
            $sets = array_values(array_filter($data['sets'], fn (array $set): bool => $set['basis'] === 'references' && in_array('app/Models/Widget.php', $set['files'], true)));
            expect($sets)->toHaveCount(1)
                ->and($sets[0]['files'])->toBe([
                    'app/Http/Controllers/WidgetController.php', 'app/Http/Requests/StoreWidgetRequest.php', 'app/Models/Widget.php',
                    'app/Policies/WidgetPolicy.php', 'database/factories/WidgetFactory.php', 'database/migrations/2026_01_01_000000_seed_widgets.php',
                    'resources/js/pages/Widget/Show.vue', 'routes/web.php',
                ])
                ->and($data['sets'])->toContain(['basis' => 'folder', 'files' => ['app/Models/Gadget.php', 'app/Models/Widget.php'], 'types' => ['model']])
                ->and($data['naming_patterns'])->toContain(['files' => ['app/Http/Controllers/GadgetController.php', 'app/Http/Controllers/WidgetController.php'], 'pattern' => '{name}Controller.php', 'type' => 'controller']);
        } finally {
            $loader->unregister();
        }
    });
});

it('does not execute opaque placement callbacks when inferring policy links', function () {
    Workspace::run([
        'roots' => [
            'models' => ['namespace' => 'App\\Models\\', 'path' => 'app/Models'],
            'policies' => ['namespace' => 'App\\Policies\\', 'path' => 'app/Policies'],
        ],
        'kinds' => [
            'model' => ['shape' => 'class', 'name' => 'as-given', 'root' => 'models', 'segments' => []],
            'policy' => ['shape' => 'class', 'name' => ['suffix' => 'Policy'], 'root' => 'policies', 'place' => fn (): string => throw new RuntimeException('Survey executed a placement callback')],
        ],
        'relations' => ['policy' => ['from' => 'model', 'to' => 'policy', 'scope' => 'same', 'mode' => 'reference']],
    ], function (Workspace $w) {
        $w->write('app/Models/Bird.php', '<?php namespace App\\Models; class Bird {}');
        $w->write('app/Policies/BirdPolicy.php', '<?php namespace App\\Policies; class BirdPolicy {}');
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect(array_column($data['classes'], 'class'))->toBe(['App\\Models\\Bird', 'App\\Policies\\BirdPolicy'])
            ->and($data['references'])->toBe([])
            ->and($data['unresolved'])->toBe([['path' => 'app/Policies/BirdPolicy.php', 'reason' => 'unsupported']]);
    });
});

it('never includes protected paths or their contents in the survey', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        foreach (['.env', '.env.local.php', 'storage/app/Hidden.php', 'app/.env.production', 'keys/private.pem', 'keys/private.key', 'logs/debug.php', 'database/database.sqlite', 'backup.sql', 'debug.log.1', 'bootstrap/cache/config.php', 'public/build/Generated.php', 'public/hot', 'public/storage/Hidden.php'] as $path) {
            $w->write($path, '<?php namespace Secrets; class NeverRead {} // SECRET_CANARY');
        }
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect(json_encode($data))->not->toContain('SECRET_CANARY', 'NeverRead', '.env', 'storage/', '.pem', '.key', '.sqlite', '.log', 'backup.sql', 'bootstrap/cache', 'public/build', 'public/hot', 'public/storage');
    });
});

it('refuses caller paths including absolute traversal and symlink arguments', function (string $path) {
    Workspace::run(null, function (Workspace $w) use ($path) {
        $tool = app(SurveyTool::class);
        $response = $tool->handle(new Request(['path' => $path === 'absolute' ? dirname($w->root->path) : $path]));
        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('base_path()', 'no arguments');
    });
})->with(['absolute', '../sibling', 'app/../../sibling', 'linked-app']);

it('skips discovered symlinks outside the app and protected in-tree aliases', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        OwnedAppRoot::using(function (OwnedAppRoot $outside) use ($w) {
            file_put_contents($outside->path('Outside.php'), '<?php class OutsideSecret {}');
            if (! @symlink($outside->path, $w->root->path('app/linked'))) {
                Assert::markTestSkipped('This filesystem cannot create symlinks.');
            }
            $tool = app(SurveyTool::class);
            try {
                $response = $tool->handle(new Request);
                expect($response->isError())->toBeFalse()
                    ->and(Scenario::data($response, $tool)['skipped'])->toBe([['path' => 'app/linked', 'reason' => 'symlink']])
                    ->and((string) $response->content())->not->toContain($outside->path, 'OutsideSecret');
            } finally {
                // Remove the directory link before its target is destroyed; Windows requires rmdir.
                if (PHP_OS_FAMILY === 'Windows') {
                    rmdir($w->root->path('app/linked'));
                } else {
                    unlink($w->root->path('app/linked'));
                }
            }
            $w->write('storage/Hidden.php', '<?php class HiddenSecret {}');
            symlink($w->root->path('storage/Hidden.php'), $w->root->path('app/Hidden.php'));
            $data = Scenario::data($tool->handle(new Request), $tool);
            expect(json_encode($data))->not->toContain('HiddenSecret')
                ->and($data['skipped'])->toBe([['path' => 'app/Hidden.php', 'reason' => 'symlink']]);
        });
    });
});

it('reports local git identity and clean dirty staged and detached states without writes', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        $git = function (array $args) use ($w): string {
            $process = new Process(['git', '-c', 'user.name=Survey test', '-c', 'user.email=survey@example.test', ...$args], $w->root->path);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $git(['init', '-b', 'survey-fixture']);
        $git(['config', 'core.autocrlf', 'false']);
        $git(['remote', 'add', 'origin', 'https://user:SECRET_TOKEN@example.test/app.git?token=SECRET_QUERY']);
        $w->write('.gitignore', ".env*\nstorage/\nvendor/\nignored.txt\n");
        $git(['add', '.']);
        $git(['commit', '-m', 'Create fixture']);
        $head = $git(['rev-parse', 'HEAD']);
        $index = file_get_contents($w->root->path('.git/index'));
        $tool = app(SurveyTool::class);
        $read = fn (): array => Scenario::data($tool->handle(new Request), $tool)['git'];
        expect($read())->toBe(['branch' => 'survey-fixture', 'commit' => $head, 'dirty' => false, 'remote' => 'https://example.test/app.git', 'root_commit' => $head]);
        $w->write('ignored.txt', 'ignored');
        expect($read()['dirty'])->toBeFalse();
        $w->write('new.txt', 'new');
        expect($read()['dirty'])->toBeTrue()->and(file_get_contents($w->root->path('.git/index')))->toBe($index);
        $git(['add', 'new.txt']);
        expect($read()['dirty'])->toBeTrue();
        $git(['commit', '-m', 'Add file']);
        $git(['checkout', '--detach']);
        expect($read()['branch'])->toBeNull()->and($read()['root_commit'])->toBe($head)->and($read()['dirty'])->toBeFalse();
        $w->write('new.txt', 'modified and longer');
        expect($read()['dirty'])->toBeTrue();
        $git(['add', 'new.txt']);
        $git(['commit', '-m', 'Update file']);
        $w->write('new.txt', 'MODIFIED AND LONGER');
        expect($read()['dirty'])->toBeTrue();
        $w->write('.git/config', $w->read('.git/config')."\n[include]\npath = /outside/config\n");
        expect($read())->toBe(['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null]);
    });
});

it('finds recurring graph and co-location sets in a configured layout and keeps namespace scopes separate', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        foreach (['Red', 'Blue'] as $group) {
            $w->write("app/Modules/{$group}/Models/Item.php", "<?php namespace App\\Modules\\{$group}\\Models; class Item {}");
            $w->write("app/Modules/{$group}/Models/Label.php", "<?php namespace App\\Modules\\{$group}\\Models; class Label {}");
            $w->write("app/Modules/{$group}/Http/Controllers/ItemController.php", "<?php namespace App\\Modules\\{$group}\\Http\\Controllers { use App\\Modules\\{$group}\\Models\\Item as Record; class ItemController {} }");
        }
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect($data['recurring_sets'])->toContain([
            'basis' => 'references',
            'occurrences' => [
                ['app/Modules/Blue/Http/Controllers/ItemController.php', 'app/Modules/Blue/Models/Item.php'],
                ['app/Modules/Red/Http/Controllers/ItemController.php', 'app/Modules/Red/Models/Item.php'],
            ],
            'types' => ['controller', 'model'],
        ], [
            'basis' => 'folder',
            'occurrences' => [
                ['app/Modules/Blue/Models/Item.php', 'app/Modules/Blue/Models/Label.php'],
                ['app/Modules/Red/Models/Item.php', 'app/Modules/Red/Models/Label.php'],
            ],
            'types' => ['model'],
        ]);
    });
});

it('retains model-centred sets when shared controllers and routes connect the whole app', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        $w->write('app/Http/Controllers/Controller.php', '<?php namespace App\\Http\\Controllers; class Controller {}');
        $w->write('routes/web.php', '<?php use App\\Http\\Controllers\\{BirdController, TreeController};');
        foreach (['Bird', 'Tree'] as $name) {
            $w->write("app/Models/{$name}.php", "<?php namespace App\\Models; class {$name} {}");
            $w->write("app/Http/Requests/{$name}Request.php", "<?php namespace App\\Http\\Requests; class {$name}Request {}");
            $w->write("app/Http/Controllers/{$name}Controller.php", "<?php namespace App\\Http\\Controllers; use App\\Models\\{$name}; use App\\Http\\Requests\\{$name}Request; class {$name}Controller extends Controller {}");
        }
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        $sets = array_values(array_filter($data['sets'], fn (array $set): bool => $set['basis'] === 'model'));
        expect($sets)->toHaveCount(2);
        foreach (['Bird', 'Tree'] as $name) {
            $set = array_values(array_filter($sets, fn (array $set): bool => $set['anchor'] === "app/Models/{$name}.php"))[0];
            expect($set['files'])->toBe([
                ...($name === 'Bird' ? ['app/Http/Controllers/BirdController.php', 'app/Http/Controllers/Controller.php'] : ['app/Http/Controllers/Controller.php', 'app/Http/Controllers/TreeController.php']),
                "app/Http/Requests/{$name}Request.php", "app/Models/{$name}.php", 'routes/web.php',
            ]);
        }
        expect(array_column($data['recurring_sets'], 'basis'))->toContain('model');
    });
});

it('skips oversized files and admits files at the size cap', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        $w->write('app/Oversized.php', '<?php class TooLarge {} //'.str_repeat('x', Scope::MAX_SOURCE_BYTES));
        $source = '<?php class AtLimit {} //';
        $w->write('app/AtLimit.php', $source.str_repeat('x', Scope::MAX_SOURCE_BYTES - strlen($source)));
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect($data['skipped'])->toBe([['path' => 'app/Oversized.php', 'reason' => 'size-limit']])
            ->and(array_column($data['classes'], 'class'))->toContain('AtLimit')
            ->and(array_column($data['classes'], 'class'))->not->toContain('TooLarge');
    });
});

it('returns deterministic exact JSON for a realistic Laravel app', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', null);
        SurveyFixture::using($w, function () use ($w) {
            $before = Scenario::bytes($w);
            $tool = app(SurveyTool::class);
            $response = $tool->handle(new Request);
            $data = Scenario::data($response, $tool);
            $fixture = __DIR__.'/../../Fixtures/Boost/survey-laravel.json';
            if (getenv('REGENERATE_SURVEY_FIXTURE') === '1') {
                file_put_contents($fixture, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
            expect($data)->toBe(json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR))
                ->and((string) $tool->handle(new Request)->content())->toBe((string) $response->content())
                ->and(Scenario::bytes($w))->toBe($before)
                ->and(json_encode($data))->not->toContain($w->root->path, ...SurveyFixture::SECRETS);
        });
    });
});

it('still refuses explicit outside scope reads', function () {
    Workspace::run(null, function (Workspace $w) {
        $scope = new Scope($w->root->path);
        expect(fn () => $scope->read('../Outside.php'))->toThrow(ModException::class, 'base_path()');
    });
});

it('does not let Git read a skipped linked ignore file', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        $process = new Process(['git', 'init'], $w->root->path);
        $process->mustRun();
        $w->write('storage/ignore.txt', 'SECRET_IGNORE');
        if (! @symlink($w->root->path('storage/ignore.txt'), $w->root->path('.gitignore'))) {
            Assert::markTestSkipped('This filesystem cannot create symlinks.');
        }
        $tool = app(SurveyTool::class);
        $data = Scenario::data($tool->handle(new Request), $tool);
        expect($data['skipped'])->toBe([['path' => '.gitignore', 'reason' => 'symlink']])
            ->and($data['git'])->toBe(['branch' => null, 'commit' => null, 'dirty' => null, 'remote' => null, 'root_commit' => null])
            ->and(json_encode($data))->not->toContain('SECRET_IGNORE');
    });
});
