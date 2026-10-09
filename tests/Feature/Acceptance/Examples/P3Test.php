<?php

use Tey\Mod\Layout\LayoutRegistry;

it('P3 overrides mirrored pages and components while preserving the remaining frontend defaults', function () {
    $registry = new LayoutRegistry;
    $registry->layout('modules')->frontend(
        pages: 'resources/js/pages/{module}',
        components: 'resources/js/components/{module}',
        pageName: '{module}/{path}',
    );
    $frontend = $registry->compile('modules')->frontend();
    expect($frontend)->toBe([
        'pages' => 'resources/js/pages/{module}',
        'components' => 'resources/js/components/{module}',
        'css' => 'app/Modules/{module}/resources/css',
        'views' => 'app/Modules/{module}/resources/views',
        'page_name' => '{module}/{path}',
    ]);
});
