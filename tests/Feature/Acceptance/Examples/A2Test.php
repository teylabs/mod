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
        foreach ($data['templates']['items'] as &$template) {
            $template['path'] = str_replace(Path::normalize($w->root->path).'/', '', Path::normalize($template['path']));
        }
        unset($template);
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
        $before = Scenario::bytes($w);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $types = array_column($data['types'], null, 'command');
        expect($types['mod:card']['extension'])->toBe('.blade.php')
            ->and($types['mod:card']['plain'])->toBeTrue();
        $templates = array_values(array_filter($data['templates']['items'], static fn (array $item): bool => $item['type'] === 'card'));
        expect(array_column($templates, 'source'))->toBe(['app', 'module:Inventory'])
            ->and(array_map(Path::normalize(...), array_column($templates, 'path')))->toBe([
                'stubs/mod/@module/resources/views/card.blade.php.stub',
                'app/Modules/Inventory/stubs/mod/@module/resources/views/card.blade.php.stub',
            ]);
        $scaffolds = array_column($data['scaffolds']['items'], null, 'name');
        expect($scaffolds['stock-report'])->toBe([
            'name' => 'stock-report', 'command' => 'mod:stock-report', 'source' => 'module:Inventory',
            'members' => [[
                'alias' => 'card', 'type' => 'card', 'name' => '{name}', 'stub' => null,
                'options' => [], 'folder' => 'app/Modules/{module}/resources/views',
                'ungrouped' => false, 'group' => null, 'existing' => 'keep',
            ]],
            'from' => 'module:Inventory', 'origin' => 'app/Modules/Inventory/Scaffolds/A2StockReport.php',
        ])->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([])
            ->and(Scenario::bytes($w))->toBe($before);
    });
});
