<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples as Tree;

it('S20 replaces only a package node and exposes a public read-only tree for L6', function () {
    Workspace::run(null, function (Workspace $w) {
        Tree::setup($w);
        Mod::scaffold('audited-tab-page', fn (Scaffold $s) => $s->include('tab-page')->makes('view-model', name: '{name}{tab}AuditedViewModel', as: 'page', stub: 'tab-page'));
        Mod::scaffold('resource-tabs.tab', fn (Part $p) => $p->uses('audited-tab-page', with: ['base' => '{{ base.fqcn }}'])->inserts(into: 'base', at: 'tabs', stub: 'tabs-entry')->inserts(into: 'controller', at: 'actions', stub: 'tabs-action'));
        Tree::create($w, ['History']);
        expect($w->exists('app/Modules/Inventory/ViewModels/WidgetHistoryAuditedViewModel.php'))->toBeTrue()->and($w->exists(Tree::page('History')))->toBeFalse();
        $nodes = app(ScaffoldRegistry::class)->nodes();
        expect($nodes['resource-tabs']['key'])->toBe('resource-tabs')->and($nodes['resource-tabs']['children'])->toBe(['resource-tabs.tab'])
            ->and($nodes['resource-tabs.tab']['source'])->toBe('app')->and($nodes['resource-tabs.tab']['uses'])->toBe('audited-tab-page');
    });
});

it('S20 renders the finite dot-path tree with uses and per-node provenance', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(ScaffoldRegistry::class);
        $registry->register('tab-page', fn (Scaffold $s) => $s->makes('view-model', as: 'page'), 'acme/tabs-kit');
        $registry->register('resource-tabs', fn (Scaffold $s) => $s->makes('controller')->part('tab', uses: 'tab-page'), 'acme/tabs-kit');
        Mod::scaffold('audited-tab-page', fn (Scaffold $s) => $s->makes('view-model', as: 'page'));
        Mod::scaffold('resource-tabs.tab', fn (Part $p) => $p->uses('audited-tab-page'));
        $before = $w->files();
        $result = $w->artisan('mod:list')->assertSuccessful();
        $output = $result->normalisedOutput();
        $section = substr($output, (int) strpos($output, '  Scaffolds'));
        expect($section)->toBe("  Scaffolds\nScaffold            Uses             From                          \naudited-tab-page                     app                           \nresource-tabs                        acme/tabs-kit                 \n└ resource-tabs.tab audited-tab-page app (overrides acme/tabs-kit) \ntab-page                             acme/tabs-kit                 \n  Discovery: off\n");
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $nodes = array_column($data['scaffolds']['items'], null, 'name');
        expect($nodes['resource-tabs']['children'])->toBe(['resource-tabs.tab'])
            ->and($nodes['resource-tabs.tab']['uses'])->toBe('audited-tab-page')
            ->and($nodes['resource-tabs.tab']['members'][0]['alias'])->toBe('page')
            ->and($w->files())->toBe($before);
    });
});
