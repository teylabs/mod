<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S14 writes three tab pages and shows all six inserts before success', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        $result = $w->artisan('mod:resource-tabs', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 5 files and 6 inserts', 'tab.Overview.page', 'tab.Details.page', 'tab.Notes.page');
        $paths = [Tree::base(), 'app/Modules/Inventory/Http/Controllers/WidgetController.php', Tree::page('Overview'), Tree::page('Details'), Tree::page('Notes')];
        $aliases = ['base', 'controller', 'tab.Overview.page', 'tab.Details.page', 'tab.Notes.page'];
        $output = "\n   INFO  mod:resource-tabs will write 5 files and 6 inserts for Inventory:Widget.  \n\n";
        foreach ($paths as $index => $path) {
            $alias = $aliases[$index];
            $output .= '  '.$path.' '.str_repeat('.', max(2, 72 - strlen($path) - strlen($alias) - 4)).' '.$alias."\n";
        }
        foreach (['Overview', 'Details', 'Notes'] as $tab) {
            foreach ([['tabs', 'ManageWidgetViewModel.php', 'entry'], ['actions', 'WidgetController.php', 'action']] as [$anchor, $file, $alias]) {
                $row = '+ at // mod:'.$anchor.' in '.$file;
                $output .= '  '.$row.' '.str_repeat('.', max(2, 72 - strlen($row) - strlen($alias) - 4)).' '.$alias."\n";
            }
        }
        $output .= "\n";
        foreach ($paths as $index => $path) {
            $label = $index === 1 ? 'Controller' : 'View model';
            $output .= "   INFO  {$label} [{$path}] created successfully.  \n\n";
        }
        foreach (['Overview', 'Details', 'Notes'] as $tab) {
            foreach ([[Tree::base(), 'tabs'], [$paths[1], 'actions']] as [$path, $anchor]) {
                $output .= "   INFO  Inserted into [{$path}] at // mod:{$anchor}.  \n\n";
            }
        }
        expect($result->normalisedOutput())->toBe($output);
        foreach (['Overview', 'Details', 'Notes'] as $tab) {
            expect($w->read(Tree::page($tab)))->toContain('extends ManageWidgetViewModel', "return '$tab';")
                ->and($w->read(Tree::base()))->toContain("['label' => '$tab'");
        }
        expect(str_replace("\r\n", "\n", $w->read(Tree::base())))->toBe(str_replace("\r\n", "\n", Tree::expectedBase()));
        expect(substr_count($w->read(Tree::base()), '// mod:tabs'))->toBe(1);
        $output = $result->normalisedOutput();
        expect(substr_count(substr($output, 0, (int) strpos($output, 'created successfully')), '+ at '))->toBe(6);
    });
});
