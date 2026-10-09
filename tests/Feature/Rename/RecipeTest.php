<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('binds historical question flags declared only by the effective layout recipe', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::layout('modules')->scaffolds('layout-only', fn (Scaffold $s) => $s->asks('historical')->makes('model'));
        $data = S::preview($w, ['--scaffold' => 'layout-only', '--historical' => 'original']);
        expect($data['warnings'])->toBe([])->and($data['selection']['answers'])->toBe(['historical' => 'original']);
    });
});

it('isolates member placement overrides from following members and moves an ungrouped formatter', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('placement', fn (Scaffold $s) => $s->makes('class', name: 'StockContract', as: 'contract', group: 'Shared', existing: 'keep')->makes('model')->makes('class', name: 'Support/{name}Formatter', as: 'formatter', ungrouped: true));
        $w->write('app/Modules/Shared/StockContract.php', "<?php\nnamespace App\\Modules\\Shared;\nclass StockContract {}\n");
        $w->write('app/Support/WidgetFormatter.php', "<?php\nnamespace App\\Support;\nclass WidgetFormatter {}\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'placement', 'new' => 'Catalog:Gadget']);
        expect($data['warnings'])->toBe([])->and(array_column($data['moves'], 'to'))->toBe(['app/Modules/Catalog/Models/Gadget.php', 'app/Support/GadgetFormatter.php']);
    });
});

it('forwards qualified repeated and nested question answers without prompts', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('nested', fn (Scaffold $s) => $s->makes('model')->asks('tabs', type: 'list')->each('tabs', 'tab')->part('tab', configure: fn (Part $p) => $p->asks('label')->makes('class', name: '{name}{label}', as: 'label')));
        $w->write('app/Modules/Inventory/WidgetOverview.php', "<?php\nnamespace App\\Modules\\Inventory;\nclass WidgetOverview {}\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'nested', '--tabs' => ['Overview'], '--answer' => ['tab.Overview.label="Overview"']]);
        expect($data['warnings'])->toBe([])->and($data['selection']['answers'])->toBe(['tab.Overview.label' => 'Overview', 'tabs' => ['Overview']]);
    });
});

it('freezes the effective source recipe rather than selecting the destination recipe', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $registry = app(ScaffoldRegistry::class);
        $registry->register('model-only', fn (Scaffold $s) => $s->makes('model'), source: 'module:Inventory');
        $registry->register('model-only', fn (Scaffold $s) => $s->makes('model')->makes('policy'), source: 'module:Catalog');
        $data = S::preview($w, ['new' => 'Catalog:Gadget']);
        expect($data['warnings'])->toBe([])->and(count($data['moves']))->toBe(1)->and($data['selection']['source'])->toContain('Inventory');
    });
});

it('blocks target-driven template provenance and extension drift', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/Tools/probe.php.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
        $w->write('app/Modules/Catalog/stubs/mod/@module/Tools/probe.vue.stub', '<template>Target</template>');
        Mod::scaffold('probe-cluster', fn (Scaffold $s) => $s->makes('probe'));
        $w->write('app/Modules/Inventory/Tools/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Tools;\nclass Widget {}\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'probe-cluster', 'new' => 'Catalog:Gadget']);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('template source or file type changes');
    });
});

it('retains historical migrations using migration identity without replaying their timestamps', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('with-history', fn (Scaffold $s) => $s->makes('model')->makes('migration', name: 'create_widgets_table'));
        $path = 'app/Modules/Inventory/Database/Migrations/2026_10_01_090000_create_widgets_table.php';
        $w->write($path, '<?php // Historical bytes reference Widget');
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'with-history']);
        expect($data['warnings'])->toBe([])->and($data['retained'])->toBe([['alias' => 'migration', 'path' => $path, 'reason' => 'historical migration']])
            ->and(count($data['moves']))->toBe(1)->and($w->read($path))->toBe('<?php // Historical bytes reference Widget');
    });
});

it('blocks unused answers and allows an explicitly empty part history', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('empty-tabs', fn (Scaffold $s) => $s->makes('model')->asks('tabs', type: 'list', default: ['Overview'])->each('tabs', 'tab')->part('tab', configure: fn (Part $p) => $p->makes('class', name: '{name}{tab}')));
        expect(S::preview($w, ['--answer' => ['wrong="value"']])['would_write'])->toBeFalse();
        $data = S::preview($w, ['--scaffold' => 'empty-tabs', '--answer' => ['tabs=[]']]);
        expect($data['warnings'])->toBe([])->and(count($data['moves']))->toBe(1)->and($data['selection']['answers'])->toBe(['tabs' => []]);
    });
});
