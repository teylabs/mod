<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('S22 disables include cycles and orphan dotted definitions without breaking other commands', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('crud', fn (Scaffold $s) => $s->include('api')->makes('model'));
        Mod::scaffold('api', fn (Scaffold $s) => $s->include('crud')->makes('controller'));
        Mod::scaffold('resource.tabs', fn (Scaffold $s) => $s->makes('view-model'));
        $all = Artisan::all();
        expect($all)->toHaveKey('mod:model');
        $problems = app(ScaffoldRegistry::class)->problems();
        expect($problems['crud'])->toContain('crud → api → crud')->and($problems['api'])->toContain('include')
            ->and($problems['resource.tabs'])->toContain("Scaffold names can't contain dots");
    });
});
