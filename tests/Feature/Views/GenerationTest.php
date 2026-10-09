<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

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
