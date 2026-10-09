<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

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

it('P3 uses mirrored frontend folders and page identity patterns', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $plan = json_decode($w->artisan('mod:page', ['name' => 'Inventory:Widget/Index', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][0]['path'])->toBe('resources/js/pages/Inventory/Widget/Index.vue')
            ->and($plan['files'][0]['identity']['name'])->toBe('Inventory/Widget/Index');
        $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect($w->exists('resources/js/pages/Inventory/Widget/Index.vue'))->toBeTrue();
    });
});

