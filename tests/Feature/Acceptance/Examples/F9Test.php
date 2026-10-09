<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F9 inserts a widget import and card at separate Vue anchors', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/metric-card.vue.stub', '<template><p>{{ count }}</p></template>');
        $w->write('stubs/mod.class.metric.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
        $w->write('stubs/mod.view-model.dashboard.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n// mod:widgets\n}\n");
        $w->write('stubs/mod.page.dashboard.vue.stub', Frontend::source('F9', 'stubs/mod.page.dashboard.vue.stub'));
        $w->write('stubs/mod.insert.dashboard-metric.stub', "'{{ widget.metric }}',\n");
        $w->write('stubs/mod.insert.dashboard-card.stub', '        <{{ widget.card }} :metric="metrics.{{ widget.camel }}" />');
        $w->write('stubs/mod.insert.dashboard-card-import.stub', "import {{ widget.card }} from '{{ widget.card.import }}';");
        Mod::scaffold('metric', fn (Scaffold $s) => $s->makes('class', name: 'Metrics/{name}Metric', as: 'metric', stub: 'metric')->makes('metric-card', name: '{name}Card', as: 'card'));
        Mod::scaffold('dashboard', fn (Scaffold $s) => $s->makes('view-model', name: '{name}DashboardViewModel', as: 'dashboard', stub: 'dashboard')->makes('page', name: '{name}/Dashboard', as: 'view', stub: 'dashboard')->part('widget', uses: 'metric', configure: fn (Part $p) => $p->inserts(into: 'dashboard', at: 'widgets', stub: 'dashboard-metric')->inserts(into: 'view', at: 'card-imports', stub: 'dashboard-card-import')->inserts(into: 'view', at: 'widgets', stub: 'dashboard-card')));
        $w->artisan('mod:dashboard', ['name' => 'Inventory:Stock'])->assertSuccessful();
        $result = $w->artisan('mod:dashboard.widget', ['name' => 'Inventory:Stock', 'item' => 'LowStock'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 2 files and 3 inserts', '<!-- mod:widgets -->')
            ->and($w->read('app/Modules/Inventory/resources/js/pages/Stock/Dashboard.vue'))->toContain("import LowStockCard from '@modules/Inventory/resources/js/components/LowStockCard.vue';", '<LowStockCard :metric="metrics.lowStock" />');
    });
});
