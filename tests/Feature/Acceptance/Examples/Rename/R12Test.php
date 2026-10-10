<?php

use Tey\Mod\Rename\Git\Transaction;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Tables\ServiceProvider;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Rename\Tables\Support\FrozenCreator;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

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
        $index = file_get_contents($w->root->path('.git/index'));
        $transaction = new Transaction($w->root->path, static function (string $phase): void {
            if (str_starts_with($phase, 'before-stage:')) {
                throw new RuntimeException('migration staging failure');
            }
        });
        expect($transaction->execute(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only', tableMigration: true, yes: true), app(Planner::class)->build(...), static function (): void {}, fn () => true))->toBe(1)
            ->and(file_get_contents($w->root->path('.git/index')))->toBe($index)->and($w->exists($path))->toBeFalse()->and($w->read($history))->toBe($bytes);
        S::apply($w, ['--table-migration' => true]);
        expect($w->read($path))->toBe($file->bytes)->and($w->read($history))->toBe($bytes);
        // Separate occupied destination preflight uses the original cluster again.
        S::git($w, ['mv', '--', 'app/Modules/Inventory/Models/Gadget.php', 'app/Modules/Inventory/Models/Widget.php']);
        $w->write('app/Modules/Inventory/Models/Widget.php', str_replace('class Gadget', 'class Widget', $w->read('app/Modules/Inventory/Models/Widget.php')));
        $w->write($path, '<?php // occupied');
        S::commit($w);
        $collision = S::preview($w, ['--table-migration' => true]);
        expect($collision['would_write'])->toBeFalse()->and(implode(' ', array_column($collision['warnings'], 'message')))->toContain('already exists');
    });
})->with(["\n", "\r\n"]);

it('R12 asks the table question once before executing the complete final plan', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('app/Modules/Inventory/Models/Widget.php', '<?php namespace App\Modules\Inventory\Models; use Illuminate\Database\Eloquent\Model; class Widget extends Model {}');
        S::commit($w);
        Examples::testCase()->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])
            ->expectsConfirmation('Create a reversible rename-table migration from widgets to gadgets?', 'no')->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Gadget.php'))->toContain('class Gadget extends Model')
            ->and(array_values(array_filter($w->files(), static fn (string $path): bool => str_contains($path, 'rename_widgets_to_gadgets'))))->toBe([]);
    });
});
