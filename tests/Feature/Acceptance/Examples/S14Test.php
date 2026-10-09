<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S14 writes three tab pages and shows all six inserts before success', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $result = $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 5 files and 6 inserts', 'tab.Overview.page', 'tab.Details.page', 'tab.Notes.page');
        foreach (['Overview', 'Details', 'Notes'] as $tab) {
            expect($w->read(Tree::page($tab)))->toContain('extends ManageWidgetViewModel', "return '$tab';")
                ->and($w->read(Tree::base()))->toContain("['label' => '$tab'");
        }
        expect(substr_count($w->read(Tree::base()), '// mod:tabs'))->toBe(1);
        $output = $result->normalisedOutput();
        expect(substr_count(substr($output, 0, (int) strpos($output, 'created successfully')), '+ at '))->toBe(6);
    });
});
