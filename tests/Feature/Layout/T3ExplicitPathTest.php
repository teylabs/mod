<?php

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('uses an explicit token instead of deriving one from an inherited layout name', function (string $name) {
    Workspace::run(null, function (Workspace $w) use ($name) {
        config()->set('mod.layout', $name);
        Mod::layout($name)->extends('modules')->path('app/Modules/{module}');
        $w->artisan('mod:list')->assertSuccessful();
        $w->artisan('mod:model', ['name' => 'Inventory:Widget'])->assertSuccessful()
            ->expectsOutputToContain('Model [app/Modules/Inventory/Models/Widget.php] created successfully.');
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toContain('namespace App\\Modules\\Inventory\\Models;');
    });
})->with(['portable', 'trial-modules']);

it('rejects an invalid derived token only when compilation needs the name', function (bool $inherits) {
    $layout = (new LayoutRegistry)->layout('trial-modules');
    if ($inherits) {
        $layout->extends('modules');
    } else {
        $layout->mounts('app', 'App\\', 'app')->generates('tool', in: 'Tools');
    }
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, "trial-modules can't name a group; declare ->path('app/{group}').");
})->with([true, false]);
