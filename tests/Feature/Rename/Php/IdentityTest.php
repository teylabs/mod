<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('rewrites only bound framework identity calls and reports computed class and historical uses', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/resources/views/show-view.blade.php.stub', 'Manual');
        Mod::scaffold('pages', fn (Scaffold $s) => $s->makes('model')->makes('page', '{name}/Show')->makes('show-view', name: '{name.kebab}s/show'));
        $w->write('app/Modules/Inventory/resources/js/pages/Widget/Show.vue', '<template>Manual</template>');
        $w->write('app/Modules/Inventory/resources/views/widgets/show.blade.php', 'Manual');
        $source = <<<'SOURCE'
<?php
use Inertia\Inertia as Screen;
Screen::render('Inventory::Widget/Show');
inertia('Inventory::Widget/Show');
view('inventory::widgets.show');
$label = 'Inventory::Widget/Show';
$thing->view('inventory::widgets.show');
Other::render('Inventory::Widget/Show');
$dynamic = new $class;
class_exists('App\\Modules\\Inventory\\Models\\Widget');
SOURCE;
        $w->write('app/Screen.php', $source);
        $historical = '<?php $x = new \App\Modules\Inventory\Models\Widget;';
        $w->write('app/Modules/Inventory/Database/Migrations/2026_01_01_000000_create_widgets.php', $historical);
        S::commit($w);
        $plan = S::preview($w, ['--scaffold' => 'pages']);
        expect($plan['warnings'])->toBe([]);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'pages'));
        $expected = str_replace(["Screen::render('Inventory::Widget/Show')", "inertia('Inventory::Widget/Show')", "\nview('inventory::widgets.show')"], ["Screen::render('Inventory::Gadget/Show')", "inertia('Inventory::Gadget/Show')", "\nview('inventory::gadgets.show')"], $source);
        expect($result->bodies['app/Screen.php'])->toBe($expected);
        expect(array_column($plan['checklist'], 'category'))->toContain('dynamic-class', 'historical-migration', 'unsupported-identity');
        expect($w->read('app/Modules/Inventory/Database/Migrations/2026_01_01_000000_create_widgets.php'))->toBe($historical);
    });
});

it('keeps identity kinds separate and locates computed render calls', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('page-only', fn (Scaffold $s) => $s->makes('model')->makes('page', '{name}/Show'));
        $w->write('app/Modules/Inventory/resources/js/pages/Widget/Show.vue', 'Manual');
        $source = '<?php use Inertia\Inertia; view(\'Inventory::Widget/Show\'); inertia($name); Inertia::render($prefix . \'/Show\');';
        $w->write('app/Computed.php', $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'page-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies['app/Computed.php'] ?? $source)->toBe($source);
        expect(array_column($result->plan->rename['checklist'], 'category'))->toContain('dynamic-identity', 'unsupported-identity');
    });
});

it('does not rewrite a helper shadowed by a scanned namespace function', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('page-only', fn (Scaffold $s) => $s->makes('model')->makes('page', '{name}/Show'));
        $w->write('app/Modules/Inventory/resources/js/pages/Widget/Show.vue', 'Manual');
        $w->write('app/Helpers.php', '<?php namespace Custom; function inertia($value) { return $value; }');
        $source = "<?php namespace Custom; inertia('Inventory::Widget/Show');";
        $w->write('app/Custom.php', $source);
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'page-only'));
        expect($result->plan->warnings)->toBe([]);
        expect($result->bodies['app/Custom.php'] ?? $source)->toBe($source);
        expect(array_column($result->plan->rename['checklist'], 'category'))->toContain('unsupported-identity');
    });
});
