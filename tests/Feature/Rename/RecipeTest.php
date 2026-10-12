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

it('moves an owned page subtree once and maps render literals only for declared entry stems', function (?array $entries, string $targetGroup) {
    Workspace::run(null, function (Workspace $w) use ($entries, $targetGroup) {
        S::setup($w);
        Mod::layout('modules')->frontend(pageEntries: $entries);
        Mod::scaffold('folder-pages', fn (Scaffold $s) => $s->folder('pages', '{name}', as: 'pageFolder')->makes('page', name: '{name}/Index/Page', as: 'indexPage'));
        $from = 'app/Modules/Inventory/resources/js/pages/Widget';
        $to = 'app/Modules/'.$targetGroup.'/resources/js/pages/Gadget';
        $paths = ['Index/Page.vue', 'Edit/Page.vue', 'Edit/Modal.vue', 'Layout.vue', 'Partials/Card.vue', 'notes.txt'];
        foreach ($paths as $path) {
            $w->write($from.'/'.$path, str_ends_with($path, '.vue') ? '<template>Edited '.$path.'</template>' : 'Notes');
        }
        $w->write('routes/pages.php', "<?php\nuse Inertia\\Inertia;\nInertia::render('Inventory::Widget/Index/Page');\ninertia('Inventory::Widget/Edit/Page');\ninertia('Inventory::Widget/Edit/Modal');\ninertia('Inventory::Widget/Layout');\ninertia('Inventory::Widget/Partials/Card');\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'folder-pages', 'new' => $targetGroup.':Gadget']);
        expect($data['warnings'])->toBe([])->and($data['folders'])->toBe([['alias' => 'pageFolder', 'type' => 'pages', 'path' => $from]])
            ->and($data['moves'])->toBe([['alias' => 'pageFolder', 'type' => 'folder', 'from' => $from, 'to' => $to, 'old_class' => null, 'new_class' => null]])
            ->and(array_column($data['folder_contents'], 'from'))->toHaveCount(6);
        S::apply($w, ['--scaffold' => 'folder-pages', 'new' => $targetGroup.':Gadget']);
        expect($w->read($to.'/Partials/Card.vue'))->toBe('<template>Edited Partials/Card.vue</template>')->and($w->read($to.'/notes.txt'))->toBe('Notes');
        $php = $w->read('routes/pages.php');
        foreach (['Index/Page', 'Edit/Page', 'Edit/Modal', 'Layout', 'Partials/Card'] as $path) {
            $mapped = $entries === null || ($entries !== [] && in_array(basename($path), ['Page', 'Modal'], true));
            expect($php)->toContain(($mapped ? $targetGroup.'::Gadget/' : 'Inventory::Widget/').$path);
        }
    });
})->with([[['Page', 'Modal'], 'Inventory'], [['Page', 'Modal'], 'Catalog'], [null, 'Inventory'], [[], 'Inventory']]);

it('moves separate owned page and component folders alongside regular members', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('frontend-folders', fn (Scaffold $s) => $s->folder('pages', '{name}', as: 'pages')->folder('components', '{name}', as: 'components')->makes('model')
            ->folder('pages', 'Shared', as: 'sharedPages')->makes('page', name: 'Shared/Index', existing: 'keep')->folder('views', 'Shared', as: 'sharedViews'));
        $root = 'app/Modules/Inventory/resources/js';
        $w->write($root.'/pages/Widget/Index.vue', '<template>Page</template>');
        $w->write($root.'/components/Widget/Card.vue', '<template>Card</template>');
        $w->write($root.'/pages/Shared/Index.vue', '<template>Shared page</template>');
        $w->write('app/Modules/Inventory/resources/views/Shared/card.blade.php', '<p>Shared</p>');
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'frontend-folders']);
        expect($data['warnings'])->toBe([])->and($data['folders'])->toHaveCount(4)->and($data['moves'])->toHaveCount(3);
        S::apply($w, ['--scaffold' => 'frontend-folders']);
        expect($w->read($root.'/pages/Gadget/Index.vue'))->toBe('<template>Page</template>')
            ->and($w->read($root.'/components/Gadget/Card.vue'))->toBe('<template>Card</template>')
            ->and($w->read($root.'/pages/Shared/Index.vue'))->toBe('<template>Shared page</template>')
            ->and($w->read('app/Modules/Inventory/resources/views/Shared/card.blade.php'))->toBe('<p>Shared</p>')
            ->and($w->read('app/Modules/Inventory/Models/Gadget.php'))->toContain('class Gadget');
    });
});
