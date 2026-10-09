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
        $w->write('app/Modules/Inventory/resources/views/ShadowServiceProvider.php', '<?php // plain file');
        $w->write('app/Modules/Inventory/Support/ExternalServiceProvider.php', '<?php // class candidate');
        $w->write('app/Modules/Inventory/routes/web.php', '<?php throw new \\RuntimeException("Routes were autoloaded");');
        expect($layout->isPlainFilePath('app/Modules/Inventory/resources/views/Widget.blade.php'))->toBeTrue()
            ->and($layout->isPlainFilePath('app/Modules/Inventory/Http/Resources/WidgetResource.php'))->toBeFalse();
        $attempts = [];
        $observer = static function (string $class) use (&$attempts): void {
            if (str_starts_with($class, 'App\\Modules\\Inventory\\')) {
                $attempts[] = $class;
            }
        };
        spl_autoload_register($observer);
        try {
            foreach ([null, fn () => ['app/Modules/Inventory/resources/views/Widget.blade.php', 'app/Modules/Inventory/resources/views/ShadowServiceProvider.php', 'app/Modules/Inventory/routes/web.php', 'app/Modules/Inventory/Support/ExternalServiceProvider.php']] as $candidates) {
                $attempts = [];
                $inventory = (new DiscoveryScanner($layout, $w->root->path, new Eligibility, $candidates))->scan(array_values(array_filter(DiscoveryOptions::fromConfig([])->definitionsFor($layout), static fn ($definition): bool => $definition->kindId === 'provider')));
                expect($inventory->entries)->toBe([])->and($inventory->rejections)->toBe([])
                    ->and($attempts)->toBe(['App\\Modules\\Inventory\\Support\\ExternalServiceProvider']);
            }
        } finally {
            spl_autoload_unregister($observer);
        }
    });
});
