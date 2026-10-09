<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S7 lets a layout override include the global recipe', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, 'ddd');
        Mod::layout('ddd')->generates('resource', in: 'application:{domain}/Http/Resources')
            ->scaffolds('crud', fn (Scaffold $s) => $s->include('crud')->makes('dto', name: '{name}Data')->makes('view-model', name: 'Show{name}ViewModel'));
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->exists('app/Modules/Knowledge/Http/Resources/DocumentResource.php'))->toBeTrue()
            ->and($w->exists('src/Domain/Knowledge/Data/DocumentData.php'))->toBeTrue()
            ->and($w->exists('src/Domain/Knowledge/ViewModels/ShowDocumentViewModel.php'))->toBeTrue()
            ->and($w->exists('src/Domain/Knowledge/Resources/DocumentResource.php'))->toBeFalse();
    });
});
