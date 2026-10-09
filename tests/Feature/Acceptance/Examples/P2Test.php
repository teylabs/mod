<?php

use Tey\Mod\Layout\LayoutRegistry;

it('P2 keeps the domain unchanged and puts HTTP and frontend paths in the application root', function () {
    $layout = (new LayoutRegistry)->compile('ddd');
    expect(place($layout, 'controller', 'Widget', 'Inventory')->path())->toBe('app/Modules/Inventory/Http/Controllers/WidgetController.php')
        ->and(place($layout, 'request', 'StoreWidget', 'Inventory')->path())->toBe('app/Modules/Inventory/Http/Requests/StoreWidgetRequest.php')
        ->and(place($layout, 'resource', 'WidgetResource', 'Inventory')->path())->toBe('src/Domain/Inventory/Resources/WidgetResource.php')
        ->and($layout->frontend()['pages'])->toBe('app/Modules/{domain}/resources/js/pages')
        ->and($layout->frontend()['page_name'])->toBe('{domain}::{path}')
        ->and($layout->roots()['routes']->path)->toBe('app/Modules/{domain}/routes');
});
