<?php

use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R6 preserves manual model bytes and reports explicit table strings', function (string $eol) {
    Workspace::run(null, function (Workspace $w) use ($eol) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $before = str_replace("\n", $eol, "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget {\n    protected \$table = 'widgets';\n    public function available(): bool { return \$this->stock > 3; }\n}\n");
        $w->write($path, $before);
        S::commit($w);
        $plan = S::preview($w);
        expect(array_column($plan['checklist'], 'category'))->toContain('database-name');
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->bodies[$path])->toBe(str_replace('class Widget', 'class Gadget', $before));
        $w->write($path, str_replace('class Widget', 'class StockItem', $before));
        S::commit($w);
        expect(S::preview($w)['would_write'])->toBeFalse();
        S::apply($w, success: false);
        $w->write($path, $before);
        S::commit($w);
        S::apply($w);
        expect($w->read('app/Modules/Inventory/Models/Gadget.php'))->toBe(str_replace('class Widget', 'class Gadget', $before));
    });
})->with(["\n", "\r\n"]);
