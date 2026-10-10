<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\ExecutionScenario as E;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R2 moves the ten-file cluster across modules and stages cross-root consumers', function () {
    Workspace::run(null, function (Workspace $w) {
        $moves = E::crud($w, 'Catalog:Widget');
        expect(array_column(S::preview($w, ['--scaffold' => 'crud', 'new' => 'Catalog:Widget'])['moves'], 'to', 'from'))->toBe($moves);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Catalog:Widget', '--scaffold' => 'crud', '--yes' => true])->assertSuccessful();
        foreach ($moves as $from => $to) {
            expect($w->exists($from))->toBeFalse()->and($w->exists($to))->toBeTrue();
        }
        expect($w->read('app/Modules/Catalog/Http/Controllers/WidgetController.php'))->toContain('namespace App\\Modules\\Catalog\\Http\\Controllers;', "Inertia::render('Catalog::Widget/Show'")
            ->and($w->read('app/Modules/Orders/Actions/ReserveStock.php'))->toContain('use App\\Modules\\Catalog\\Models\\Widget as StockItem;', 'return StockItem::query();')
            ->and($w->read('app/Modules/Inventory/InventoryServiceProvider.php'))->toBe('<?php // retained provider')
            ->and($w->read('app/Modules/Inventory/routes/web.php'))->toBe('<?php // retained routes')
            ->and(S::git($w, ['diff', '--name-only']))->toBe('');
    });
});
