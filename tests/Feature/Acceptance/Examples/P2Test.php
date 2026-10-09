<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P2 keeps the domain unchanged and puts HTTP and frontend paths in the application root', function () {
    $layout = (new LayoutRegistry)->compile('ddd');
    expect(place($layout, 'controller', 'Widget', 'Inventory')->path())->toBe('app/Modules/Inventory/Http/Controllers/WidgetController.php')
        ->and(place($layout, 'request', 'StoreWidget', 'Inventory')->path())->toBe('app/Modules/Inventory/Http/Requests/StoreWidgetRequest.php')
        ->and(place($layout, 'resource', 'WidgetResource', 'Inventory')->path())->toBe('src/Domain/Inventory/Resources/WidgetResource.php')
        ->and($layout->frontend()['pages'])->toBe('app/Modules/{domain}/resources/js/pages')
        ->and($layout->frontend()['page_name'])->toBe('{domain}::{path}')
        ->and($layout->roots()['routes']->path)->toBe('app/Modules/{domain}/routes');
});

it('P2 generates a DDD page in the application root', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        config()->set('mod.layout', 'ddd');
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/resources/js/pages/Widget/Index.vue'))->toBeTrue()
            ->and($result->normalisedOutput())->toContain("Inertia::render('Inventory::Widget/Index')");
    });
});

