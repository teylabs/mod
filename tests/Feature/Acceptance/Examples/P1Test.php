<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Support\Stack;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P1 declares the Laravel-shaped module tree with plain-file roots', function () {
    $layout = (new LayoutRegistry)->compile('modules');
    foreach (['controller' => 'Controllers', 'request' => 'Requests', 'middleware' => 'Middleware', 'resource' => 'Resources'] as $type => $folder) {
        expect(place($layout, $type, 'Widget', 'Inventory')->path())->toStartWith('app/Modules/Inventory/Http/'.$folder.'/');
    }
    foreach (['resources-js' => 'resources/js', 'resources-css' => 'resources/css', 'resources-views' => 'resources/views', 'routes' => 'routes'] as $root => $folder) {
        expect($layout->roots()[$root]->namespace)->toBeNull()
            ->and($layout->roots()[$root]->path)->toBe('app/Modules/{module}/'.$folder);
    }
    expect($layout->frontend())->toBe([
        'pages' => 'app/Modules/{module}/resources/js/pages',
        'components' => 'app/Modules/{module}/resources/js/components',
        'css' => 'app/Modules/{module}/resources/css',
        'views' => 'app/Modules/{module}/resources/views',
        'page_name' => '{module}::{path}',
    ]);
});

it('P1 mirrors the app pages casing without changing an explicit frontend override', function () {
    Workspace::run(null, function (Workspace $w) {
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"^2.0"}}');
        $w->write('resources/js/Pages/.gitkeep', '');
        $stack = new Stack($w->root->path);
        expect((new LayoutRegistry)->compile('modules')->frontend($stack)['pages'])->toBe('app/Modules/{module}/resources/js/Pages');
        $registry = new LayoutRegistry;
        $registry->layout('modules')->frontend(pages: 'resources/js/pages/{module}');
        expect($registry->compile('modules')->frontend($stack)['pages'])->toBe('resources/js/pages/{module}');
    });
});
