<?php

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;

it('allows frontend with every argument omitted on a custom layout', function () {
    $layout = (new Layout('custom'))->path('app')->mounts('app', 'App\\', 'app')->generates('model', in: 'Models');
    expect($layout->frontend())->toBe($layout)
        ->and($layout->compile()->frontend())->toBe(['pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null]);
});

it('resolves frontend defaults against a customized group path and inherited token', function () {
    $registry = new LayoutRegistry;
    $registry->layout('modules')->path('src/{area}')->mounts('src', 'Src\\', 'src');
    expect($registry->compile('modules')->frontend()['pages'])->toBe('src/{area}/resources/js/pages');
    $registry = new LayoutRegistry;
    $registry->layout('reports')->extends('modules');
    expect($registry->compile('reports')->frontend()['pages'])->toBe('app/Modules/{report}/resources/js/pages')
        ->and($registry->compile('reports')->frontend()['page_name'])->toBe('{report}::{path}');
});

it('refuses case-only collisions between class folders and plain-file roots', function () {
    $registry = new LayoutRegistry;
    $registry->layout('modules')->generates('resource', in: '@module/Resources');
    expect(fn () => $registry->compile('modules'))->toThrow(InvalidLayout::class, 'app/Modules/{module}/Resources and app/Modules/{module}/resources differ only by case. To keep API resources in Resources/, move the frontend root with ->frontend() to ui/.');
});

it('refuses case-only collisions between class folders too', function () {
    $layout = (new Layout('custom'))->path('app/{area}')->mounts('app', 'App\\', 'app')
        ->generates('one', in: '@area/Reports')->generates('two', in: '@area/reports');
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, 'app/{area}/Reports and app/{area}/reports differ only by case. Move one of them.');
});
