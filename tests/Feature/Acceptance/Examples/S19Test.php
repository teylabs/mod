<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S19 inserts a route using chained forms and literal parameter braces', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w, routes: true);
        $w->write('app/Modules/Inventory/routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::middleware('web')->group(function () {\n    // mod:routes\n});\n");
        $w->write('stubs/mod.insert.tab-route.stub', "    Route::get('{{ name.plural.kebab }}/{{{ model.camel }}}/{{ tab.kebab }}', [\\{{ controller.fqcn }}::class, '{{ tab.camel }}'])->name('{{ name.kebab }}.{{ tab.kebab }}');");
        Tree::create($w, ['History']);
        expect($w->read('app/Modules/Inventory/routes/web.php'))->toContain('widgets/{widget}/history', "->name('widget.history')");
        require $w->root->path('app/Modules/Inventory/routes/web.php');
        $w->artisan('route:list', ['--name' => 'widget.history'])->assertSuccessful()->expectsOutputToContain('widget.history');
    });
});

it('S19 refuses a missing routes file without a terminal and offers it in a terminal', function (bool $terminal) {
    Workspace::run(null, function (Workspace $w) use ($terminal) {
        Tree::setup($w, routes: true);
        $w->write('stubs/mod.insert.tab-route.stub', '// route {{ tab }}');
        if ($terminal) {
            Examples::testCase()->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--model' => 'Widget', '--tabs' => ['History']])
                ->expectsConfirmation('app/Modules/Inventory/routes/web.php has no mod:routes anchor. Create it with the anchor?', 'no')->assertSuccessful();
        } else {
            $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget', '--tabs' => ['History']])->assertFailed()->expectsOutputToContain('app/Modules/Inventory/routes/web.php');
        }
        expect($w->exists(Tree::base()))->toBeFalse()->and($w->exists('app/Modules/Inventory/routes/web.php'))->toBeFalse();
    });
})->with([false, true]);
