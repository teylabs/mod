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
            $registry->register('crud', fn (Scaffold $s) => $s->makes('model'), $source, vendor: 'acme');
        }
        expect(Artisan::all()['mod:acme/crud']->isHidden())->toBeFalse();
        expect($registry->details('acme/crud')['notices'][0]['code'])->toBe('vendor_scaffold_conflict');
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
    });
});
