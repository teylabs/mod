<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('previews route generators without writes and resolves ddd application paths', function (string $command, string $layout, string $path) {
    Workspace::run(null, function (Workspace $w) use ($command, $layout, $path) {
        config()->set('mod.layout', $layout);
        $json = json_decode($w->artisan($command, ['module' => 'Inventory', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($json['files'][0]['path'])->toBe($path)->and($json['would_write'])->toBeTrue();
        expect($w->files())->toBe([]);
        $w->artisan($command, ['module' => 'Inventory'])->assertSuccessful();
        $original = $w->read($path);
        $w->artisan($command, ['module' => 'Inventory'])->assertFailed()->expectsOutputToContain('--force');
        expect($w->read($path))->toBe($original);
        $w->artisan($command, ['module' => 'Inventory', '--force' => true])->assertSuccessful();
    });
})->with([
    ['mod:routes', 'modules', 'app/Modules/Inventory/routes/web.php'],
    ['mod:routes', 'ddd', 'app/Modules/Inventory/routes/web.php'],
    ['mod:route-registrar', 'modules', 'app/Modules/Inventory/Http/Routing/InventoryRoutes.php'],
    ['mod:route-registrar', 'ddd', 'app/Modules/Inventory/Http/Routing/InventoryRoutes.php'],
]);

it('offers overwriting in a terminal', function (string $command, string $path) {
    Workspace::run(null, function (Workspace $w) use ($command, $path) {
        config()->set('mod.layout', 'modules');
        $w->write($path, 'keep me');
        test()->artisan($command, ['module' => 'Inventory'])
            ->expectsConfirmation("{$command}: {$path} already exists. Overwrite it?", 'no')->assertSuccessful();
        expect($w->read($path))->toBe('keep me');
    });
})->with([
    ['mod:routes', 'app/Modules/Inventory/routes/web.php'],
    ['mod:route-registrar', 'app/Modules/Inventory/Http/Routing/InventoryRoutes.php'],
]);
