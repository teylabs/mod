<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('P1 exposes compiled module frontend paths and pins every frontend key', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"^2"}}');
        $w->write('resources/js/Pages/.gitkeep', '');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend'])->toBe([
            'pages' => 'app/Modules/{module}/resources/js/Pages',
            'components' => 'app/Modules/{module}/resources/js/components',
            'css' => 'app/Modules/{module}/resources/css',
            'views' => 'app/Modules/{module}/resources/views',
            'page_name' => '{module}::{path}',
            'view_namespace' => '{module.kebab}',
            'import_alias' => ['alias' => '@modules', 'root' => 'app/Modules'],
        ])->and(array_diff_key($data['frontend'], ['view_namespace' => null, 'import_alias' => null]))->toBe(app(CompiledLayout::class)->frontend());
        $types = array_column($data['types'], 'folder', 'id');
        expect($types['controller'])->toBe('app/Modules/{module}/Http/Controllers')
            ->and($types['request'])->toBe('app/Modules/{module}/Http/Requests')
            ->and($types['middleware'])->toBe('app/Modules/{module}/Http/Middleware')
            ->and($types['resource'])->toBe('app/Modules/{module}/Http/Resources')
            ->and($data)->not->toHaveKey('upgrade');
        $schema = (new InventorySectionRegistry)->schema();
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        $original = $data;
        foreach (array_keys($data['frontend']) as $key) {
            $missing = $original;
            unset($missing['frontend'][$key]);
            expect(JsonSchema::errors($missing, $schema))->toContain('$.frontend.'.$key.' is required');
        }
        $data['frontend']['future'] = true;
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        $data['frontend']['pages'] = 123;
        expect(JsonSchema::errors($data, $schema))->not->toBeEmpty();
        unset($data['frontend']);
        expect(JsonSchema::errors($data, $schema))->toContain('$.frontend is required');
    });
});

it('P2 exposes DDD frontend paths from the application root', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend'])->toBe([
            'pages' => 'app/Modules/{domain}/resources/js/pages',
            'components' => 'app/Modules/{domain}/resources/js/components',
            'css' => 'app/Modules/{domain}/resources/css',
            'views' => 'app/Modules/{domain}/resources/views',
            'page_name' => '{domain}::{path}',
            'view_namespace' => '{domain.kebab}',
            'import_alias' => ['alias' => '@modules', 'root' => 'app/Modules'],
        ]);
    });
});

it('P3 exposes mirrored frontend overrides with their declared page name', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['pages'])->toBe('resources/js/pages/{module}')
            ->and($data['frontend']['components'])->toBe('resources/js/components/{module}')
            ->and($data['frontend']['page_name'])->toBe('{module}/{path}')
            ->and($data['frontend']['views'])->toBe('app/Modules/{module}/resources/views');
    });
});

it('uses renamed dimensions for a custom layout view namespace', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->extends('modules')->path('src/{area}');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend']['pages'])->toBe('src/{area}/resources/js/pages')
            ->and($data['frontend']['view_namespace'])->toBe('{area.kebab}');
    });
});

it('exports nullable frontend fields for a layout without frontend declarations', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'custom');
        Mod::layout('custom')->path('app')->mounts('app', 'App\\', 'app')->generates('model', in: 'Models');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['frontend'])->toBe(['pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null, 'view_namespace' => null, 'import_alias' => null])
            ->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([]);
    });
});
