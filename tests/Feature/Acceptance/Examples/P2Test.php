<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('P2 keeps the domain unchanged and puts HTTP paths in the application root', function () {
    $layout = (new LayoutRegistry)->compile('ddd');
    expect(place($layout, 'controller', 'Widget', 'Inventory')->path())->toBe('app/Modules/Inventory/Controllers/WidgetController.php')
        ->and(place($layout, 'request', 'StoreWidget', 'Inventory')->path())->toBe('app/Modules/Inventory/Requests/StoreWidgetRequest.php')
        ->and(place($layout, 'resource', 'WidgetResource', 'Inventory')->path())->toBe('src/Domain/Inventory/Resources/WidgetResource.php')
        ->and($layout->frontend()['pages'])->toBeNull()
        ->and($layout->frontend()['page_name'])->toBeNull()
        ->and($layout->roots())->not->toHaveKey('routes');
});

it('P2 refuses a DDD page without frontend configuration', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        config()->set('mod.layout', 'ddd');
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertFailed();
        expect($w->exists('app/Modules/Inventory/resources/js/pages/Widget/Index.vue'))->toBeFalse()
            ->and($result->normalisedOutput())->toContain('->frontend(pages: ...)');
    });
});
