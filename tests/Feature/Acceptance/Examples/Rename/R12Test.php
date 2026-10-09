<?php

use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Tables\ServiceProvider;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Rename\Tables\Support\FrozenCreator;

it('R12 preserves historical bytes and returns the exact reversible candidate without writes', function (string $eol) {
    Workspace::run(null, function (Workspace $w) use ($eol) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        app()->instance('migration.creator', new FrozenCreator(app('files'), $w->root->path('stubs')));
        $w->write('app/Modules/Inventory/Models/Widget.php', str_replace("\n", $eol, "<?php\nnamespace App\\Modules\\Inventory\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Widget extends Model {}\n"));
        $history = 'app/Modules/Inventory/Database/Migrations/2026_10_01_090000_create_widgets_table.php';
        $bytes = str_replace("\n", $eol, "<?php\nuse App\\Modules\\Inventory\\Models\\Widget;\nSchema::create('widgets', fn (\$table) => \$table->id());\n");
        $w->write($history, $bytes);
        S::commit($w);
        $report = S::preview($w);
        expect($report['files'])->toBe([])->and($report['warnings'])->toBe([]);
        expect(array_column($report['checklist'], 'category'))->toContain('migration', 'inferred-table');
        $json = S::preview($w, ['--table-migration' => true]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only', tableMigration: true));
        $path = 'app/Modules/Inventory/Database/Migrations/2026_10_09_163000_rename_widgets_to_gadgets_table.php';
        expect($json['warnings'])->toBe([])->and($json['files'])->toBe([[
            'alias' => 'table-migration:model', 'type' => 'migration', 'path' => $path,
            'identity' => ['name' => 'rename_widgets_to_gadgets_table', 'from_table' => 'widgets', 'to_table' => 'gadgets'],
            'group' => 'Inventory', 'existing' => false, 'exists' => false,
        ]]);
        expect(array_keys($json))->toBe(['command', 'group', 'name', 'files', 'inserts', 'warnings', 'would_write', 'selection', 'target', 'moves', 'rewrites', 'retained', 'checklist', 'scan_roots']);
        expect($result->generated)->toHaveCount(1);
        $file = $result->generated[0];
        expect($file->path)->toBe($path)->and($file->mode)->toBe(0644)->and($file->timestamp)->toBe('2026_10_09_163000');
        expect($file->bytes)->toBe(file_get_contents(__DIR__.'/../../../../Fixtures/rename/rename_widgets_to_gadgets_table.stub'));
        expect($w->exists($path))->toBeFalse()->and($w->read($history))->toBe($bytes);
        expect(array_column($json['rewrites'], 'file'))->not->toContain($history);
        $w->write($path, '<?php // occupied');
        S::commit($w);
        $collision = S::preview($w, ['--table-migration' => true]);
        expect($collision['would_write'])->toBeFalse()->and(implode(' ', array_column($collision['warnings'], 'message')))->toContain('already exists');
    });
})->with(["\n", "\r\n"]);
