<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * The Mod facade's reads: the compiled active layout, and the layouts there are.
 */

it('returns the compiled active layout from Mod::current()', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'modules');

        expect(Mod::current())->toBeInstanceOf(CompiledLayout::class)
            ->and(Mod::current())->toBe(app(CompiledLayout::class))
            ->and(Mod::current()->hasKind('dto'))->toBeTrue()
            ->and(Mod::current()->placementOptions())->toBe(['module' => 'module']);
    });
});
