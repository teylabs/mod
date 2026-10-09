<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S18 refuses missing anchors or duplicate tabs before writing in either mode', function (bool $terminal, string $problem) {
    Workspace::run(null, function (Workspace $w) use ($terminal, $problem) {
        Tree::setup($w);
        Tree::create($w);
        if ($problem === 'anchor') {
            $w->write(Tree::base(), str_replace('// mod:tabs', '', $w->read(Tree::base())));
        }
        $files = [];
        foreach ($w->files() as $file) {
            $files[$file] = $w->read($file);
        }
        $args = ['name' => 'Inventory:Widget', 'value' => $problem === 'anchor' ? 'History' : 'Notes'];
        if ($terminal) {
            Examples::testCase()->artisan('mod:resource-tabs.tab', $args)->assertFailed();
        } else {
            $w->artisan('mod:resource-tabs.tab', $args)->assertFailed();
        }
        foreach ($files as $file => $contents) {
            expect($w->read($file))->toBe($contents);
        }
        expect($w->files())->toBe(array_keys($files));
    });
})->with([false, true])->with(['anchor', 'duplicate']);

it('S18 names the root command for a missing cluster without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertFailed()->expectsOutputToContain('Run mod:resource-tabs Inventory:Widget --tabs=History first.');
        expect($w->exists(Tree::page('History')))->toBeFalse();
    });
});

it('S18 offers to create a missing root before growing it', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Examples::testCase()->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])
            ->expectsConfirmation('There is no ManageWidgetViewModel in Inventory. Create the Widget resource with mod:resource-tabs first?', 'no')->assertSuccessful();
        expect($w->exists(Tree::base()))->toBeFalse();
    });
});
