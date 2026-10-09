<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R14 pins the complete read-only rename envelope', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        expect(S::preview($w))->toBe([
            'command' => 'mod:rename', 'group' => 'Inventory', 'name' => 'Widget', 'files' => [], 'inserts' => [], 'warnings' => [], 'would_write' => true,
            'selection' => ['scaffold' => 'model-only', 'source' => 'app', 'answers' => []],
            'target' => ['group' => 'Inventory', 'name' => 'Gadget'],
            'moves' => [['alias' => 'model', 'type' => 'model', 'from' => 'app/Modules/Inventory/Models/Widget.php', 'to' => 'app/Modules/Inventory/Models/Gadget.php', 'old_class' => 'App\\Modules\\Inventory\\Models\\Widget', 'new_class' => 'App\\Modules\\Inventory\\Models\\Gadget']],
            'rewrites' => [['file' => 'app/Modules/Inventory/Models/Widget.php', 'after_file' => 'app/Modules/Inventory/Models/Gadget.php', 'line' => 3, 'category' => 'php-declaration', 'before' => 'Widget', 'after' => 'Gadget']],
            'retained' => [], 'checklist' => [], 'scan_roots' => ['app', 'bootstrap', 'config', 'resources', 'routes', 'tests'],
        ]);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--json' => true])->assertFailed()->expectsOutputToContain('mod:rename --json is a preview option. Add --dry-run. Nothing was written.');
    });
});

it('R14 prints the same human plan with original paths and locations', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $output = $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--dry-run' => true])->assertSuccessful()->normalisedOutput();
        expect($output)->toEqualText("Rename Inventory:Widget -> Inventory:Gadget (model-only)\napp/Modules/Inventory/Models/Widget.php -> app/Modules/Inventory/Models/Gadget.php\nRewrite app/Modules/Inventory/Models/Widget.php:3 [php-declaration]: Widget -> Gadget\nMove: 1 files\nReview: 0 items\nDry run. Nothing was written.\n");
    });
});
