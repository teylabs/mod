<?php

use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S8 prefers the app recipe and retains package sources for listing', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $registry = app(ScaffoldRegistry::class);
        $registry->register('crud', fn (Scaffold $s) => $s->makes('job'), 'acme/inertia-kit');
        $registry->register('inertia-page', fn (Scaffold $s) => $s->makes('controller'), 'acme/inertia-kit');
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($registry->sources()['crud'])->toBe('app (overrides acme/inertia-kit)')
            ->and($registry->sources()['inertia-page'])->toBe('acme/inertia-kit')
            ->and($w->exists(Examples::paths()[0]))->toBeTrue();
    });
});
