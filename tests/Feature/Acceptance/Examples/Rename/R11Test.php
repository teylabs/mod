<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Frontend\Frontend;
use Tey\Mod\Rename\Frontend\Runner;
use Tey\Mod\Rename\Frontend\RunResult;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R11 preserves unavailable frontend bytes with original lines and intended alias targets', function (string $extension) {
    Workspace::run(null, function (Workspace $w) use ($extension) {
        S::setup($w);
        Mod::scaffold('page-only', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show'));
        $file = 'app/Modules/Inventory/resources/js/pages/Widget/Show.vue';
        $source = "<script setup lang=\"ts\">\r\nimport Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\r\nconst label = 'Inventory::Widget/Show';\r\n</script>";
        $source = str_replace('Show.vue', 'Show'.$extension, $source);
        $w->write($file, $source);
        app(Contributors::class)->set('frontend', new Frontend(new class implements Runner
        {
            public function run(string $basePath, string $input): RunResult
            {
                return new RunResult(null, 'Node parsing is unavailable.');
            }
        }));
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'page-only']);
        expect($data['warnings'])->toBe([])->and($data['moves'])->toHaveCount(1)->and($data['rewrites'])->toBe([]);
        $row = array_values(array_filter($data['checklist'], static fn (array $row): bool => $row['line'] === 2))[0];
        expect($row['file'])->toBe($file)->and($row['after_file'])->toBe(str_replace('Widget', 'Gadget', $file))->and($row['category'])->toBe('frontend-toolchain')->and($row['suggestion'])->toBe('@modules/Inventory/resources/js/pages/Gadget/Show'.$extension);
        S::apply($w, ['--scaffold' => 'page-only']);
        expect($w->read(str_replace('Widget', 'Gadget', $file)))->toBe($source);
    });
})->with(['.vue', '']);

it('R11 maps Blade directive and anonymous component identities without touching PHP expressions or comments', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/resources/views/show-view.blade.php.stub', '<p>Template</p>');
        Mod::scaffold('view-only', fn (Scaffold $s) => $s->makes('show-view', as: 'view', name: '{name.kebab}s/show'));
        $w->write('app/Modules/Inventory/resources/views/widgets/show.blade.php', '<p>Edited body</p>');
        $w->write('resources/views/consumer.blade.php', "@include('inventory::widgets.show')\n{{ 'inventory::widgets.show' }}\n{{-- @include('inventory::widgets.show') --}}\n@include(\$view)\n");
        app(Contributors::class)->set('frontend', new Frontend);
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'view-only']);
        expect($data['warnings'])->toBe([])->and($data['rewrites'])->toHaveCount(1)->and($data['rewrites'][0]['before'])->toBe('inventory::widgets.show')->and($data['rewrites'][0]['after'])->toBe('inventory::gadgets.show')->and($data['rewrites'][0]['line'])->toBe(1);
        expect(in_array('identity-string', array_column($data['checklist'], 'category'), true))->toBeTrue();
        expect(array_values(array_filter($data['checklist'], static fn (array $row): bool => $row['category'] === 'blade-identity'))[0]['line'])->toBe(4);
        S::apply($w, ['--scaffold' => 'view-only']);
    });
});

it('R11 combines PHP render and view calls with Blade identities when frontend parsing is unavailable', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/resources/views/show-view.blade.php.stub', '<p>Template</p>');
        Mod::scaffold('render-views', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show')->makes('show-view', as: 'view', name: '{name.kebab}s/show'));
        $page = 'app/Modules/Inventory/resources/js/pages/Widget/Show.vue';
        $frontend = "<script setup>import Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';</script>";
        $w->write($page, $frontend);
        $w->write('app/Modules/Inventory/resources/views/widgets/show.blade.php', '<p>Edited body</p>');
        $php = <<<'SOURCE'
<?php
use Inertia\Inertia as Screen;
Screen::render('Inventory::Widget/Show');
inertia('Inventory::Widget/Show');
view('inventory::widgets.show');
$label = 'Inventory::Widget/Show';
SOURCE;
        $blade = <<<'SOURCE'
@include('inventory::widgets.show')
{{ view('inventory::widgets.show') }}
{{ inertia('Inventory::Widget/Show') }}
{{ 'inventory::widgets.show' }}
{{-- @include('inventory::widgets.show') --}}
SOURCE;
        $w->write('app/Screen.php', $php);
        $w->write('resources/views/consumer.blade.php', $blade);
        app(Contributors::class)->set('frontend', new Frontend(new class implements Runner
        {
            public function run(string $basePath, string $input): RunResult
            {
                return new RunResult(null, 'Node parsing is unavailable.');
            }
        }));
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'render-views']);
        expect($data['warnings'])->toBe([])->and($data['moves'])->toHaveCount(2);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'render-views'));
        $expectedPhp = str_replace(["Screen::render('Inventory::Widget/Show')", "inertia('Inventory::Widget/Show')", "view('inventory::widgets.show')"], ["Screen::render('Inventory::Gadget/Show')", "inertia('Inventory::Gadget/Show')", "view('inventory::gadgets.show')"], $php);
        $expectedBlade = str_replace(["view('inventory::widgets.show')", "inertia('Inventory::Widget/Show')"], ["view('inventory::gadgets.show')", "inertia('Inventory::Gadget/Show')"], substr_replace($blade, "@include('inventory::gadgets.show')", 0, strlen("@include('inventory::widgets.show')")));
        expect($result->bodies['app/Screen.php'])->toBe($expectedPhp)->and($result->bodies['resources/views/consumer.blade.php'])->toBe($expectedBlade);
        $bladeEdits = array_values(array_filter($data['rewrites'], static fn (array $row): bool => $row['file'] === 'resources/views/consumer.blade.php'));
        expect(array_column($bladeEdits, 'category'))->toBe(['blade-identity', 'php-identity', 'php-identity'])->and(array_column($bladeEdits, 'line'))->toBe([1, 2, 3]);
        expect($result->bodies[$page] ?? $frontend)->toBe($frontend);
        expect(array_column($data['checklist'], 'category'))->toContain('frontend-toolchain', 'unsupported-identity', 'identity-string');
        S::apply($w, ['--scaffold' => 'render-views']);
    });
});
