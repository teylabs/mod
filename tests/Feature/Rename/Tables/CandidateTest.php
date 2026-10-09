<?php

use Illuminate\Database\Migrations\MigrationCreator;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Tables\Contributor;
use Tey\Mod\Rename\Tables\ServiceProvider;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Rename\Tables\Support\FrozenCreator;

it('refuses native custom creators without calling their writer', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        app()->instance('migration.creator', new class(app('files'), $w->root->path('stubs')) extends MigrationCreator
        {
            public function create($name, $path, $table = null, $create = false)
            {
                throw new LogicException('Never call the app creator');
            }
        });
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        expect(S::preview($w)['warnings'])->toBe([]);
        $json = S::preview($w, ['--table-migration' => true]);
        expect($json['files'])->toBe([])->and($json['would_write'])->toBeFalse();
        expect(implode(' ', array_column($json['warnings'], 'message')))->toContain('app-custom migration creator');
    });
});

it('follows compiled migration roots and target contexts', function (string $layout, string $model, string $namespace, string $target, string $expected) {
    Workspace::run(null, function (Workspace $w) use ($layout, $model, $namespace, $target, $expected) {
        S::setup($w);
        config()->set('mod.layout', $layout);
        if ($layout === 'ddd') {
            $w->remove(['app/Modules/Inventory/Models/Widget.php']);
            $w->write('src/Domain/Catalog/Models/.gitkeep', '');
        } else {
            Mod::layout('modules')->generates('migration', in: 'app:Modules/{module}/History', timestamped: true);
        }
        app()->forgetInstance(CompiledLayout::class);
        app(CompiledLayout::class);
        $w->write($model, "<?php\nnamespace {$namespace};\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        app()->register(ServiceProvider::class);
        app()->instance('migration.creator', new FrozenCreator(app('files'), $w->root->path('stubs')));
        S::commit($w);
        $json = S::preview($w, ['new' => $target, '--table-migration' => true]);
        expect($json['warnings'])->toBe([])->and($json['files'][0]['path'])->toBe($expected);
        expect($json['moves'][0]['to'])->not->toContain('/Http/');
    });
})->with([
    ['modules', 'app/Modules/Inventory/Models/Widget.php', 'App\\Modules\\Inventory\\Models', 'Catalog:Gadget', 'app/Modules/Catalog/History/2026_10_09_163000_rename_widgets_to_gadgets_table.php'],
    ['ddd', 'src/Domain/Inventory/Models/Widget.php', 'Domain\\Inventory\\Models', 'Catalog:Gadget', 'src/Domain/Catalog/Database/Migrations/2026_10_09_163000_rename_widgets_to_gadgets_table.php'],
]);

it('rejects duplicate complete candidates through the shared composer', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        app()->instance('migration.creator', new FrozenCreator(app('files'), $w->root->path('stubs')));
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        app(Contributors::class)->set('duplicate-tables', app(Contributor::class));
        $json = S::preview($w, ['--table-migration' => true]);
        expect($json['would_write'])->toBeFalse()->and(implode(' ', array_column($json['warnings'], 'message')))->toContain('duplicated');
    });
});

it('uses the native default clock without invoking migration generation', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only', tableMigration: true));
        expect($result->plan->warnings)->toBe([])->and($result->generated)->toHaveCount(1);
        $file = $result->generated[0];
        expect($file->timestamp)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}$/')->and(basename($file->path))->toBe($file->timestamp.'_rename_widgets_to_gadgets_table.php');
    });
});
