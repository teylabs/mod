<?php

use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('A2 pins the full Vue inventory with every previous section intact', function () {
    Workspace::run(null, function (Workspace $w) {
        Scenario::inventory($w);
        $before = Scenario::bytes($w);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->normalisedOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['routes'] as &$route) {
            if (is_string($route['loaded_by'])) {
                $route['loaded_by'] = str_replace(Path::normalize($w->root->path).'/', '', Path::normalize($route['loaded_by']));
            }
        }
        unset($route);
        $output = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $output = str_replace(Path::normalize(dirname(__DIR__, 4)), '<package>', $output);
        expect($output)->toEqualText(file_get_contents(__DIR__.'/../../../Fixtures/Boost/a2-vue.json'));
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['stack'])->toBe(['inertia' => 'vue', 'typescript' => true, 'pages' => 'resources/js/pages'])
            ->and($data['frontend']['view_namespace'])->toBe('{module.kebab}')
            ->and($data['frontend']['import_alias'])->toBe(['alias' => '@modules', 'root' => 'app/Modules'])
            ->and($data['views'][0]['namespace'])->toBe('inventory')
            ->and($data['routes'][0])->toBe([
                'group' => 'Inventory', 'entrypoint' => 'app/Modules/Inventory/routes/web.php',
                'kind' => 'file', 'middleware_group' => 'web', 'order' => 1,
                'loaded_by' => 'bootstrap/app.php:18',
            ])
            ->and($data['wiring'])->toBe(['inertia' => true, 'vite_alias' => true, 'tailwind' => true])
            ->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([])
            ->and(Scenario::bytes($w))->toBe($before);
    });
});

it('A2 composes module-owned template and scaffold sources after L6', function () {
    Workspace::run(null, function (Workspace $w) {
        Scenario::inventory($w);
        $w->write('app/Modules/Inventory/stubs/mod/@module/resources/views/card.blade.php.stub', '<div>{{ name.studly }}</div>');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $types = array_column($data['types'], null, 'command');
        expect($types['mod:card']['source'])->toBe('module:Inventory');
        $templates = array_column($data['templates']['items'], null, 'name');
        expect($templates['card']['source'])->toBe('module:Inventory')
            ->and($templates['card']['extension'])->toBe('.blade.php');
        // Pin the full source snapshot and module scaffold rows after L6 lands.
    });
})->skip('Waiting for L6 to merge before pinning its module-source contract.');
