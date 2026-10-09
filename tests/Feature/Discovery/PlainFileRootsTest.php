<?php

use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryScanner;
use Tey\Mod\Discovery\Eligibility;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('never treats Blade and route files as class candidates even with discovery anywhere', function () {
    Workspace::run(null, function (Workspace $w) {
        $registry = new LayoutRegistry;
        $registry->layout('modules')->generates('provider', discover: 'anywhere');
        $layout = $registry->compile('modules');
        $w->write('app/Modules/Inventory/resources/views/Widget.blade.php', '<?php throw new \\RuntimeException("Blade was autoloaded");');
        $w->write('app/Modules/Inventory/routes/web.php', '<?php throw new \\RuntimeException("Routes were autoloaded");');
        expect($layout->isPlainFilePath('app/Modules/Inventory/resources/views/Widget.blade.php'))->toBeTrue()
            ->and($layout->isPlainFilePath('app/Modules/Inventory/Http/Resources/WidgetResource.php'))->toBeFalse();
        foreach ([null, fn () => ['app/Modules/Inventory/resources/views/Widget.blade.php', 'app/Modules/Inventory/routes/web.php']] as $candidates) {
            $inventory = (new DiscoveryScanner($layout, $w->root->path, new Eligibility, $candidates))->scan(DiscoveryOptions::fromConfig([])->definitionsFor($layout));
            expect($inventory->entries)->toBe([])->and($inventory->rejections)->toBe([]);
        }
    });
});
