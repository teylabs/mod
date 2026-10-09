<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('reports the module alias root from compiled frontend folders', function (string $layout, string $root) {
    Workspace::run(null, function (Workspace $w) use ($layout, $root) {
        config()->set('mod.layout', $layout);
        if ($layout === 'areas') {
            Mod::layout('areas')->extends('modules')->path('src/{area}');
        }
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['import_alias'])->toBe(['alias' => '@modules', 'root' => $root]);
    });
})->with([['modules', 'app/Modules'], ['ddd', 'app/Modules'], ['areas', 'src'], ['features', 'app'], ['slices', 'app'], ['type-first', 'app'], ['laravel', 'app']]);

it('keeps the app import rule for mirrored pages while exposing the module alias', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['import_alias'])->toBe(['alias' => '@modules', 'root' => 'app/Modules']);
    });
});

it('reports null for an undeclared frontend', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'custom');
        Mod::layout('custom')->path('app')->mounts('app', 'App\\', 'app')->generates('model', in: 'Models');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['import_alias'])->toBeNull();
    });
});
