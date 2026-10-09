<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates and plans a notification without a view when markdown is omitted', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $options = ['name' => 'Inventory:StockChanged'];
        $preview = $w->artisan('mod:notification', [...$options, '--dry-run' => true, '--json' => true])->assertSuccessful();
        $plan = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'])->toHaveCount(1)->and($w->files())->toBe([]);
        $w->artisan('mod:notification', $options)->assertSuccessful();
        expect($w->files())->toBe(['app/Modules/Inventory/Notifications/StockChanged.php']);
    });
});

it('qualifies an explicit notification markdown view in the native class', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->artisan('mod:notification', ['name' => 'Inventory:StockChanged', '--markdown' => 'mail.stock-changed'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Notifications/StockChanged.php'))->toContain("->markdown('inventory::mail.stock-changed')")
            ->and($w->exists('app/Modules/Inventory/resources/views/mail/stock-changed.blade.php'))->toBeTrue();
    });
});

it('keeps native notification behavior for a bare markdown flag', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $supportsBareFlag = Artisan::all()['make:notification']->getDefinition()->getOption('markdown')->getDefault() === false;
        $options = ['name' => 'Inventory:StockChanged', '--markdown' => null];
        $preview = $w->artisan('mod:notification', [...$options, '--dry-run' => true, '--json' => true])->assertSuccessful();
        $plan = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'])->toHaveCount($supportsBareFlag ? 2 : 1);
        $w->artisan('mod:notification', $options)->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/resources/views/mail/stock-changed.blade.php'))->toBe($supportsBareFlag);
    });
});

it('describes every view write and identity without writing', function (string $command, array $options, int $count, string $identity) {
    Workspace::run(null, function (Workspace $w) use ($command, $options, $count, $identity) {
        config()->set('mod.layout', 'modules');
        $before = $w->files();
        $result = $w->artisan($command, ['name' => 'Inventory:'.($command === 'mod:view' ? 'widgets.show' : 'StockBadge'), ...$options, '--dry-run' => true, '--json' => true]);
        $plan = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($result->exitCode)->toBe(0)->and($plan['would_write'])->toBeTrue()->and($plan['files'])->toHaveCount($count)
            ->and($plan['files'][$count - 1]['identity']['name'])->toBe($identity)->and($w->files())->toBe($before);
    });
})->with([
    ['mod:view', [], 1, 'inventory::widgets.show'],
    ['mod:component', ['--view' => true], 1, 'inventory::components.stock-badge'],
    ['mod:component', [], 2, 'inventory::components.stock-badge'],
    ['mod:mail', ['--markdown' => 'mail.stock'], 2, 'inventory::mail.stock'],
    ['mod:mail', ['--view' => 'mail.stock'], 2, 'inventory::mail.stock'],
    ['mod:notification', ['--markdown' => 'mail.stock'], 2, 'inventory::mail.stock'],
]);

it('refuses an existing companion view before writing the class', function (string $command, array $options, string $view, string $class) {
    Workspace::run(null, function (Workspace $w) use ($command, $options, $view, $class) {
        config()->set('mod.layout', 'modules');
        $path = 'app/Modules/Inventory/resources/views/'.$view.'.blade.php';
        $w->write($path, 'keep');
        $result = $w->artisan($command, ['name' => 'Inventory:StockBadge', ...$options]);
        expect($result->exitCode)->toBe(1)->and($result->normalisedOutput())->toContain($command, '--force')
            ->and($w->read($path))->toBe('keep')->and($w->exists('app/Modules/Inventory/'.$class.'/StockBadge.php'))->toBeFalse();
        $preview = json_decode($w->artisan($command, ['name' => 'Inventory:StockBadge', ...$options, '--dry-run' => true, '--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['would_write'])->toBeFalse()->and($preview['files'][1]['exists'])->toBeTrue();
    });
})->with([
    ['mod:component', [], 'components/stock-badge', 'View/Components'],
    ['mod:mail', ['--markdown' => 'mail.stock'], 'mail/stock', 'Mail'],
    ['mod:notification', ['--markdown' => 'mail.stock'], 'mail/stock', 'Notifications'],
]);

it('places DDD views and components in the application root', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $w->write('app/Modules/Inventory/Http/Controllers/.gitkeep', '');
        expect($w->artisan('mod:view', ['name' => 'Inventory:widgets.show'])->exitCode)->toBe(0)
            ->and($w->exists('app/Modules/Inventory/resources/views/widgets/show.blade.php'))->toBeTrue();
        expect($w->artisan('mod:component', ['name' => 'Inventory:StockBadge'])->exitCode)->toBe(0)
            ->and($w->read('app/Modules/Inventory/View/Components/StockBadge.php'))->toContain('namespace App\\Modules\\Inventory\\View\\Components;');
    });
});

it('keeps Laravel views unqualified in resources views', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'laravel');
        $result = $w->artisan('mod:view', ['name' => 'widgets.show']);
        expect($result->exitCode)->toBe(0)->and($w->exists('resources/views/widgets/show.blade.php'))->toBeTrue()
            ->and($result->normalisedOutput())->toContain("view('widgets.show')");
    });
});

it('puts type-first group views in kebab subfolders and ungrouped views in the app folder', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'type-first');
        expect($w->artisan('mod:view', ['name' => 'Inventory:widgets.show'])->exitCode)->toBe(0)
            ->and($w->exists('resources/views/inventory/widgets/show.blade.php'))->toBeTrue();
        expect($w->artisan('mod:view', ['name' => 'widgets.show'])->exitCode)->toBe(0)
            ->and($w->exists('resources/views/widgets/show.blade.php'))->toBeTrue();
    });
});

it('overwrites every planned companion view with force', function (string $command, array $options, string $view) {
    Workspace::run(null, function (Workspace $w) use ($command, $options, $view) {
        config()->set('mod.layout', 'modules');
        $path = 'app/Modules/Inventory/resources/views/'.$view.'.blade.php';
        $w->write($path, 'old');
        $result = $w->artisan($command, ['name' => 'Inventory:StockBadge', ...$options, '--force' => true]);
        expect($result->exitCode)->toBe(0)->and($w->read($path))->not->toBe('old');
    });
})->with([
    ['mod:component', [], 'components/stock-badge'],
    ['mod:mail', ['--markdown' => 'mail.stock'], 'mail/stock'],
    ['mod:mail', ['--view' => 'mail.stock'], 'mail/stock'],
    ['mod:notification', ['--markdown' => 'mail.stock'], 'mail/stock'],
]);

it('refuses existing anonymous views and names the force remedy', function (string $command, string $name, array $options, string $file) {
    Workspace::run(null, function (Workspace $w) use ($command, $name, $options, $file) {
        config()->set('mod.layout', 'modules');
        $w->write($file, 'keep');
        $result = $w->artisan($command, ['name' => $name, ...$options]);
        expect($result->exitCode)->toBe(1)->and($result->normalisedOutput())->toContain('--force')->and($w->read($file))->toBe('keep');
    });
})->with([
    ['mod:view', 'Inventory:widgets.show', [], 'app/Modules/Inventory/resources/views/widgets/show.blade.php'],
    ['mod:component', 'Inventory:StockBadge', ['--view' => true], 'app/Modules/Inventory/resources/views/components/stock-badge.blade.php'],
]);

it('generates views in every built-in layout', function (string $layout, string $name, string $path) {
    Workspace::run(null, function (Workspace $w) use ($layout, $name, $path) {
        config()->set('mod.layout', $layout);
        expect($w->artisan('mod:view', ['name' => $name])->exitCode)->toBe(0, $layout)
            ->and($w->exists($path.'/widgets/show.blade.php'))->toBeTrue();
    });
})->with([
    ['laravel', 'widgets.show', 'resources/views'],
    ['features', 'Inventory:widgets.show', 'app/Features/Inventory/resources/views'],
    ['slices', 'Inventory/Show:widgets.show', 'app/Inventory/Show/resources/views'],
    ['type-first', 'Inventory:widgets.show', 'resources/views/inventory'],
    ['modules', 'Inventory:widgets.show', 'app/Modules/Inventory/resources/views'],
    ['ddd', 'Inventory.Reports:widgets.show', 'app/Modules/Inventory/Reports/resources/views'],
]);

it('honours a customized views folder without changing class placement', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->frontend(views: 'ui/{module}/views');
        $result = $w->artisan('mod:component', ['name' => 'Inventory:StockBadge']);
        expect($result->exitCode)->toBe(0)->and($w->exists('ui/Inventory/views/components/stock-badge.blade.php'))->toBeTrue()
            ->and($w->read('app/Modules/Inventory/View/Components/StockBadge.php'))->toContain("view('inventory::components.stock-badge')");
    });
});

it('describes missing groups and invalid names as warnings without writing', function (string $command, string $name, array $options) {
    Workspace::run(null, function (Workspace $w) use ($command, $name, $options) {
        config()->set('mod.layout', 'modules');
        $preview = $w->artisan($command, ['name' => $name, ...$options, '--dry-run' => true, '--json' => true]);
        $data = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview->exitCode)->toBe(0)->and($data['would_write'])->toBeFalse()->and($data['warnings'])->not->toBeEmpty()->and($w->files())->toBe([]);
    });
})->with([
    ['mod:view', 'widgets.show', []],
    ['mod:component', 'StockBadge', ['--view' => true]],
    ['mod:view', 'Inventory:../../outside', []],
    ['mod:mail', 'Inventory:Restock', ['--markdown' => '../outside']],
    ['mod:view', 'Inventory:widgets.show', ['--extension' => '../php']],
]);

it('plans an inline component as one class without a view', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $preview = $w->artisan('mod:component', ['name' => 'Inventory:StockBadge', '--inline' => true, '--dry-run' => true, '--json' => true]);
        $plan = json_decode($preview->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'])->toHaveCount(1)->and($plan['files'][0]['class'])->toBe('App\\Modules\\Inventory\\View\\Components\\StockBadge')->and($w->files())->toBe([]);
    });
});

it('prints the class component tag for inline and customized view paths', function (array $options, ?string $view) {
    Workspace::run(null, function (Workspace $w) use ($options, $view) {
        config()->set('mod.layout', 'modules');
        $result = $w->artisan('mod:component', ['name' => 'Inventory:StockBadge', ...$options]);
        expect($result->exitCode)->toBe(0)->and($result->normalisedOutput())->toContain('<x-inventory::stock-badge />');
        if ($view !== null) {
            expect($w->read('app/Modules/Inventory/View/Components/StockBadge.php'))->toContain("view('inventory::{$view}')")
                ->and($w->exists('app/Modules/Inventory/resources/views/'.str_replace('.', '/', $view).'.blade.php'))->toBeTrue();
        }
    });
})->with([
    [['--inline' => true], null],
    [['--path' => 'Stock/Badges'], 'stock.badges.stock-badge'],
]);
