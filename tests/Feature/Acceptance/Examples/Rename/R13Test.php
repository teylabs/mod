<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R13 rewrites route class symbols and keeps located runtime strings', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $source = "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget {}\n\\Illuminate\\Support\\Facades\\Route::get('/widgets', [Widget::class, 'index'])->name('widgets.index');\n";
        $w->write($path, $source);
        $w->write('config/inventory.php', "<?php return ['label' => 'Widget'];\n");
        $w->write('resources/lang/en/inventory.php', "<?php return ['widgets.title' => 'Widgets'];\n");
        S::commit($w);
        $plan = S::preview($w);
        expect($plan['warnings'])->toBe([]);
        expect(array_column($plan['checklist'], 'category'))->toContain('route-uri', 'route-name', 'translation', 'config-string');
        $rows = array_values(array_filter($plan['checklist'], fn (array $row) => $row['category'] === 'route-uri'));
        expect($rows[0])->toMatchArray(['file' => $path, 'after_file' => 'app/Modules/Inventory/Models/Gadget.php', 'line' => 4]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->bodies[$path])->toBe(str_replace(['class Widget', '[Widget::'], ['class Gadget', '[Gadget::'], $source));
        S::apply($w);
    });
});

it('R13 keeps module route controller URI action and name bytes while rewriting the bound controller', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('route-cluster', fn (Scaffold $s) => $s->makes('model')->makes('controller'));
        $w->write('app/Modules/Inventory/Http/Controllers/WidgetController.php', '<?php namespace App\Modules\Inventory\Http\Controllers; class WidgetController {}');
        $path = 'app/Modules/Inventory/routes/web.php';
        $source = <<<'SOURCE'
<?php
use App\Modules\Inventory\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;
Route::get('/widgets', [WidgetController::class, 'index'])->name('widgets.index');
SOURCE;
        $w->write($path, $source);
        S::commit($w);
        $plan = S::preview($w, ['--scaffold' => 'route-cluster']);
        expect($plan['warnings'])->toBe([]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'route-cluster'));
        expect($result->bodies[$path])->toBe(str_replace('WidgetController', 'GadgetController', $source));
        $rows = array_values(array_filter($plan['checklist'], fn (array $row) => in_array($row['category'], ['route-uri', 'route-name'], true)));
        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            expect($row)->toMatchArray(['file' => $path, 'after_file' => $path, 'line' => 4]);
        }
        S::apply($w, ['--scaffold' => 'route-cluster']);
    });
});
