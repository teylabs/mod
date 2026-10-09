<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F16 generates the documented complete controller and typed index variants', function (string $stack, string $path, string $identity) {
    Workspace::run(null, function (Workspace $w) use ($stack, $path, $identity) {
        Frontend::crud($w, $stack);
        $fixtures = __DIR__.'/../../../Fixtures/Frontend/release/';
        $w->write('stubs/mod.controller.inertia-crud.stub', (string) file_get_contents($fixtures.'controller.stub'));
        if ($stack === 'react') {
            $w->write('stubs/mod.page.crud-index.tsx.stub', (string) file_get_contents($fixtures.'index.tsx.stub'));
        }
        $result = $w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget', '--no-interaction' => true])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 12 files')
            ->and($w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'))->toContain(
                'use Inertia\\Inertia;',
                'use App\\Modules\\Inventory\\Models\\Widget;',
                'use App\\Modules\\Inventory\\Http\\Resources\\WidgetResource;',
                "Inertia::render('{$identity}'",
            )->and($w->read($path))->toContain($stack === 'vue' ? 'widgets: { data: Array<{ id: number }> };' : 'widgets: { data: Array<{ id: number }> }');
    });
})->with([
    ['vue', 'app/Modules/Inventory/resources/js/pages/Widget/Index.vue', 'Inventory::Widget/Index'],
    ['react', 'app/Modules/Inventory/resources/js/pages/widget/index.tsx', 'Inventory::widget/index'],
]);
