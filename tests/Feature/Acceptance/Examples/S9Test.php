<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('S9 disables only the ambiguous package scaffold', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(ScaffoldRegistry::class);
        foreach (['acme/inertia-kit', 'beta/admin-kit'] as $source) {
            $registry->register('crud', fn (Scaffold $s) => $s->makes('model'), $source);
        }
        expect(Artisan::all()['mod:crud']->isHidden())->toBeTrue();
        expect($registry->problems())->toBe(['crud' => 'Scaffold [crud] is registered by acme/inertia-kit and by beta/admin-kit, so mod:crud is disabled. Define crud in your app to use your own.']);
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
    });
});
