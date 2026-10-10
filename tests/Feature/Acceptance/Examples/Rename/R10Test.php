<?php

use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R10 binds grouped aliases and unaliased PHP consumers without replacing labels', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'tests/Feature/Orders/StockTest.php';
        $before = <<<'SOURCE'
<?php
use App\Modules\Inventory\Models\{Widget as Stock, OtherModel};
function reserve(Stock|OtherModel $item): Stock { return $item; }
$kind = \App\Modules\Inventory\Models\Widget::class;
$example = 'Widget'; // Widget is a label
SOURCE;
        $w->write($path, $before);
        $w->write('app/Consumer.php', '<?php use App\Modules\Inventory\Models\Widget; $w = new Widget; Widget::find(1);');
        S::commit($w);
        $plan = S::preview($w);
        expect($plan['warnings'])->toBe([]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->bodies[$path])->toBe(str_replace(['{Widget as', 'Models\\Widget::'], ['{Gadget as', 'Models\\Gadget::'], $before));
        expect($result->bodies['app/Consumer.php'])->toBe('<?php use App\Modules\Inventory\Models\Gadget; $w = new Gadget; Gadget::find(1);');
    });
});
