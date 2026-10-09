<?php

use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('resolves multiple namespaces global relative types attributes traits and member lookalikes', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Bindings.php';
        $source = <<<'SOURCE'
<?php
namespace App\Modules\Inventory\Models {
#[Widget]
class Consumer extends Widget implements \Countable {
    use Widget { Widget::available as local; }
    public Widget $item;
    public function accept((Widget&\Countable)|null $item): Widget { return new namespace\Widget; }
    public function count(): int { return Widget::count(); }
}
}
namespace Other {
use App\Modules\Inventory\Models\Widget;
function accept(Widget $w): Widget { if ($w instanceof Widget) { return new Widget; } return $w; }
$x->Widget(); Other::Widget(); $Widget = 'Widget';
}
namespace Foreign { class Widget {} $w = new Widget; }
namespace { $w = new \App\Modules\Inventory\Models\Widget; }
SOURCE;
        $w->write($path, $source);
        S::commit($w);
        $plan = S::preview($w);
        expect($plan['warnings'])->toBe([]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        $expected = str_replace(['#[Widget]', 'extends Widget', 'use Widget { Widget::', 'public Widget', '(Widget&', '): Widget', 'namespace\\Widget', 'return Widget::', 'Models\\Widget', '(Widget $w)', 'instanceof Widget', 'new Widget;'], ['#[Gadget]', 'extends Gadget', 'use Gadget { Gadget::', 'public Gadget', '(Gadget&', '): Gadget', 'namespace\\Gadget', 'return Gadget::', 'Models\\Gadget', '(Gadget $w)', 'instanceof Gadget', 'new Gadget;'], $source);
        $expected = str_replace('namespace Foreign { class Widget {} $w = new Gadget;', 'namespace Foreign { class Widget {} $w = new Widget;', $expected);
        expect($result->bodies[$path])->toBe($expected);
    });
});

it('blocks import and declaration collisions and malformed consumers before any write', function (string $source) {
    Workspace::run(null, function (Workspace $w) use ($source) {
        S::setup($w);
        $w->write('app/Bad.php', $source);
        S::commit($w);
        $plan = S::preview($w);
        expect($plan['would_write'])->toBeFalse()->and($plan['warnings'])->not->toBe([]);
    });
})->with([
    '<?php use App\Modules\Inventory\Models\Widget; use Other\Gadget; new Widget;',
    '<?php use App\Modules\Inventory\Models\Widget; class Gadget {}',
    '<?php use App\Modules\Inventory\Models\Widget; function broken( {',
]);

it('moves mapped namespace imports including groups without changing same basename foreign symbols', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'tests/Consumer.php';
        $source = '<?php use App\Modules\Inventory\Models\{Widget as Stock, OtherModel}; function f(Stock $x): Stock { return $x; }';
        $w->write($path, $source);
        S::commit($w);
        expect(S::preview($w, ['new' => 'Catalog:Gadget'])['warnings'])->toBe([]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Catalog:Gadget', 'model-only'));
        expect($result->bodies[$path])->toBe('<?php use App\Modules\Catalog\Models\Gadget as Stock; use  App\Modules\Inventory\Models\OtherModel; function f(Stock $x): Stock { return $x; }');
        expect($result->bodies['app/Modules/Inventory/Models/Widget.php'])->toBe("<?php\nnamespace App\\Modules\\Catalog\\Models;\nclass Gadget {}\n");
    });
});

it('keeps each consumers original namespace while the owned declaration moves', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Peer.php';
        $w->write($path, '<?php namespace App\Modules\Inventory\Models; $x = new Widget;');
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Catalog:Gadget', 'model-only'));
        expect($result->plan->wouldWrite)->toBeTrue();
        expect($result->bodies[$path])->toBe('<?php namespace App\Modules\Inventory\Models; $x = new \App\Modules\Catalog\Models\Gadget;');
    });
});

it('never treats function calls constants or attribute arguments as class types', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Spaces.php';
        $source = '<?php use App\Modules\Inventory\Models\Widget; function f($x) { Widget($x); return Widget; } #[Other(1, Widget)] class X {}';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe(str_replace('Models\\Widget;', 'Models\\Gadget;', $source));
    });
});

it('preserves unmapped local dependencies when its owning namespace moves', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $source = '<?php namespace App\Modules\Inventory\Models; class Widget extends OtherModel { public function f(): OtherModel { return new OtherModel; } }';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Catalog:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe('<?php namespace App\Modules\Catalog\Models; class Gadget extends \App\Modules\Inventory\Models\OtherModel { public function f(): \App\Modules\Inventory\Models\OtherModel { return new \App\Modules\Inventory\Models\OtherModel; } }');
    });
});

it('expands moving mixed group imports while preserving functions constants aliases and comments', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Mixed.php';
        $source = '<?php use App\Modules\Inventory\Models\{Widget /* hand */ as Stock, function helper, const FLAG,}; $x = new Stock;';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Catalog:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe('<?php use App\Modules\Catalog\Models\Gadget /* hand */ as Stock; use  function App\Modules\Inventory\Models\helper; use  const App\Modules\Inventory\Models\FLAG; $x = new Stock;');
    });
});

it('blocks an import that converges on the same target binding even when its FQCN is identical', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('app/Converged.php', '<?php use App\Modules\Inventory\Models\Widget; use App\Modules\Inventory\Models\Gadget; new Widget;');
        S::commit($w);
        expect(S::preview($w)['would_write'])->toBeFalse();
    });
});

it('changes the owning declaration namespace rather than a later namespace in the file', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $source = '<?php namespace App\Modules\Inventory\Models { class Widget {} } namespace Other { function helper() {} }';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Catalog:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe('<?php namespace App\Modules\Catalog\Models { class Gadget {} } namespace Other { function helper() {} }');
    });
});

it('blocks a target FQCN already declared in another scanned filename', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('app/HiddenTarget.php', '<?php namespace App\Modules\Inventory\Models; class Gadget {}');
        S::commit($w);
        expect(S::preview($w)['would_write'])->toBeFalse();
    });
});

it('preserves alias spelling and unmapped qualified dependencies when the imported class changes', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $path = 'app/Aliases.php';
        $source = '<?php use App\Modules\Inventory\Models\Widget as Stock; $x = new sToCk; $y = new Stock\Inner;';
        $w->write($path, $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies[$path])->toBe('<?php use App\Modules\Inventory\Models\Gadget as Stock; $x = new sToCk; $y = new \App\Modules\Inventory\Models\Widget\Inner;');
    });
});
