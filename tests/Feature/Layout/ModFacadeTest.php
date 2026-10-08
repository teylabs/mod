<?php

use Tey\Mod\Exceptions\InvalidLayout;
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

it('says so when mod.layout is not a layout name', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', ['modules']);

        expect(fn () => Mod::current())->toThrow(InvalidLayout::class, 'Config [mod.layout] must be a layout name such as "laravel".');
    });
});

it('says which layouts there are with Mod::hasLayout() and Mod::layouts()', function () {
    Workspace::run(null, function () {
        Mod::layout('domains')->root('app', 'App\\', 'app', fn ($root) => $root->kind('model', in: 'Models'));

        expect(Mod::hasLayout('modules'))->toBeTrue()
            ->and(Mod::hasLayout('domains'))->toBeTrue()
            ->and(Mod::hasLayout('nope'))->toBeFalse()
            ->and(Mod::layouts())->toBe(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd', 'domains']);
    });
});
