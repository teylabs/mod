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
