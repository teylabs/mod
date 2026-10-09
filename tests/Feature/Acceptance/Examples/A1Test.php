<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('A1 describes all twelve CRUD files and framework identities without writing', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w);
        $before = $w->files();
        $plan = json_decode($w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['command'])->toBe('mod:crud-pages')->and($plan['group'])->toBe('Inventory')->and($plan['name'])->toBe('Widget')
            ->and($plan['files'])->toHaveCount(12)->and($plan['warnings'])->toBe([])->and($plan['would_write'])->toBeTrue()->and($w->files())->toBe($before);
        $page = array_values(array_filter($plan['files'], fn (array $file) => $file['alias'] === 'indexPage'))[0];
        expect($page['identity']['name'])->toBe('Inventory::Widget/Index')
            ->and($page['identity']['import'])->toBe('@modules/Inventory/resources/js/pages/Widget/Index.vue');
        $expected = [];
        foreach ([
            ['model', 'model', 'Models/Widget.php', 'Models\\Widget'],
            ['model (migration)', 'migration', 'Database/Migrations/2026_10_08_120000_create_widgets_table.php', null],
            ['model (factory)', 'factory', 'Database/Factories/WidgetFactory.php', 'Database\\Factories\\WidgetFactory'],
            ['storeRequest', 'request', 'Http/Requests/StoreWidgetRequest.php', 'Http\\Requests\\StoreWidgetRequest'],
            ['updateRequest', 'request', 'Http/Requests/UpdateWidgetRequest.php', 'Http\\Requests\\UpdateWidgetRequest'],
            ['resource', 'resource', 'Http/Resources/WidgetResource.php', 'Http\\Resources\\WidgetResource'],
            ['policy', 'policy', 'Policies/WidgetPolicy.php', 'Policies\\WidgetPolicy'],
            ['controller', 'controller', 'Http/Controllers/WidgetController.php', 'Http\\Controllers\\WidgetController'],
        ] as [$alias, $type, $below, $class]) {
            $path = 'app/Modules/Inventory/'.$below;
            $expected[] = ['alias' => $alias, 'type' => $type, 'path' => $path, ...($class === null ? ['identity' => ['path' => $path]] : ['class' => 'App\\Modules\\Inventory\\'.$class]), 'group' => 'Inventory', 'existing' => false, 'exists' => false];
        }
        foreach (['Index' => 'indexPage', 'Create' => 'createPage', 'Edit' => 'editPage', 'Show' => 'showPage'] as $name => $alias) {
            $path = 'app/Modules/Inventory/resources/js/pages/Widget/'.$name.'.vue';
            $expected[] = ['alias' => $alias, 'type' => 'page', 'path' => $path, 'identity' => ['path' => $path, 'component' => $name, 'name' => 'Inventory::Widget/'.$name, 'import' => '@modules/Inventory/resources/js/pages/Widget/'.$name.'.vue'], 'group' => 'Inventory', 'existing' => false, 'exists' => false];
        }
        expect($plan)->toBe(['command' => 'mod:crud-pages', 'group' => 'Inventory', 'name' => 'Widget', 'files' => $expected, 'inserts' => [], 'warnings' => [], 'would_write' => true]);
    });
});
