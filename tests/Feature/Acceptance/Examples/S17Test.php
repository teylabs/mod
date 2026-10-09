<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S17 grows the same part and preserves CRLF and anchor whitespace', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Tree::create($w, ['Overview', 'Details']);
        $before = str_replace("\n", "\r\n", str_replace('// mod:tabs', '// mod:tabs  ', str_replace("\r\n", "\n", $w->read(Tree::base()))));
        $w->write(Tree::base(), $before);
        $result = $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 1 file and 2 inserts', '+ at // mod:tabs', '+ at // mod:actions');
        $after = $w->read(Tree::base());
        expect($after)->toContain("                // mod:tabs  \r\n")->and(str_replace("\r\n", '', $after))->not->toContain("\n");
        expect(str_replace("                ['label' => 'History', 'route' => 'widget.history'],\r\n", '', $after))->toBe($before);
        $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertFailed();
        expect($w->read(Tree::base()))->toBe($after);
    });
});

it('S17 creation with three equals creation with two then growing the third', function () {
    $whole = Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Tree::create($w, ['Overview', 'Details', 'History']);

        return [$w->read(Tree::base()), $w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'), $w->read(Tree::page('History'))];
    });
    Workspace::run(null, function (Workspace $w) use ($whole) {
        Tree::setup($w);
        Tree::create($w, ['Overview', 'Details']);
        $w->artisan('mod:resource-tabs.tab', ['name' => 'Inventory:Widget', 'value' => 'History'])->assertSuccessful();
        expect([$w->read(Tree::base()), $w->read('app/Modules/Inventory/Http/Controllers/WidgetController.php'), $w->read(Tree::page('History'))])->toBe($whole);
    });
});
