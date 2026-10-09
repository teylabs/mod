<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('S12 disables invalid scaffolds without breaking other commands', function (string $problem) {
    Workspace::run(null, function (Workspace $w) use ($problem) {
        config()->set('mod.layout', 'features');
        if ($problem === 'missing') {
            Mod::scaffold('crud', fn (Scaffold $s) => $s->makes('unavailable'));
        } elseif ($problem === 'name') {
            Mod::scaffold('model', fn (Scaffold $s) => $s->makes('model'));
        } else {
            Mod::scaffold('crud', fn (Scaffold $s) => $s->makes('request')->makes('request'));
        }
        $all = Artisan::all();
        expect($all)->toHaveKey('mod:model');
        if ($problem !== 'name') {
            expect($all['mod:crud']->isHidden())->toBeTrue();
        }
        expect(app(ScaffoldRegistry::class)->problems())->not->toBeEmpty();
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
    });
})->with(['missing', 'name', 'duplicate']);
