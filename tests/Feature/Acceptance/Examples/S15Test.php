<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S15 leaf needs a base and then works without touching anchors', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $result = $w->artisan('mod:tab-page', ['name' => 'Inventory:Widget', '--tab' => 'Archive'])->assertFailed();
        expect($result->normalisedOutput())->toBe("\n   ERROR  mod:tab-page needs a base. Pass --base=<class>.  \n\n");
        $w->artisan('mod:tab-page', ['name' => 'Inventory:Widget', '--tab' => 'Archive', '--base' => 'App\\Support\\ViewModels\\ViewModel'])->assertSuccessful();
        expect($w->read(Tree::page('Archive')))->toContain('extends ViewModel')->and($w->exists(Tree::base()))->toBeFalse();
    });
});

it('S15 searches for a base in a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Examples::testCase()->artisan('mod:tab-page', ['name' => 'Inventory:Widget', '--tab' => 'Archive'])
            ->expectsQuestion('Which base view model does the page extend?', 'App\\Support\\ViewModels\\ViewModel')
            ->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        expect($w->read(Tree::page('Archive')))->toContain('extends ViewModel');
    });
});
