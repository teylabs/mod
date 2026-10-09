<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F8 writes the index filter members and keeps the app composable', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        putenv('COLUMNS=72');
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"*"}}');
        $w->write('tsconfig.json', '{}');
        $w->write('app/Modules/Inventory/Data/.gitkeep', '');
        $w->write('app/Modules/Knowledge/Data/.gitkeep', '');
        $w->write('stubs/mod.dto.index-filter.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
        $w->write('stubs/mod.query.index-filter.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n");
        $w->write('stubs/mod/@module/resources/js/components/filter-bar.vue.stub', <<<'VUE'
<script setup lang="ts">
import { useIndexFilter } from '@/composables/useIndexFilter';

const { filters, apply } = useIndexFilter({{ fields.json }});
</script>

<template>
    <form @submit.prevent="apply">
        <input v-for="field in {{ fields.json }}" :key="field" v-model="filters[field]" :placeholder="field" />
    </form>
</template>
VUE);
        $w->write('stubs/mod/resources/js/composables/composable.ts.stub', "export function {{ name }}(fields: string[]) {\n    // the house filter state, synced to the query string\n}\n");
        Mod::scaffold('index-filter', fn (Scaffold $s) => $s
            ->asks('fields', type: 'list', label: 'Which fields can be filtered?')
            ->makes('dto', name: '{name}FilterData', as: 'filters', stub: 'index-filter')
            ->makes('query', name: 'Query{name.plural}', stub: 'index-filter')
            ->makes('filter-bar', name: '{name}Filters', as: 'bar')
            ->makes('composable', name: 'useIndexFilter', ungrouped: true, existing: 'keep'));
        $result = $w->artisan('mod:index-filter', ['name' => 'Inventory:Widget', '--fields' => ['status,category']])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('will write 4 files for Inventory:Widget.', 'composable (ungrouped)')
            ->and($w->read('app/Modules/Inventory/resources/js/components/WidgetFilters.vue'))->toContain('useIndexFilter(["status","category"])')
            ->and($w->read('resources/js/composables/useIndexFilter.ts'))->toEqualText("export function useIndexFilter(fields: string[]) {\n    // the house filter state, synced to the query string\n}\n")
            ->and($w->exists('app/Modules/Inventory/Data/WidgetFilterData.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Inventory/Queries/QueryWidgets.php'))->toBeTrue();
        $shared = $w->read('resources/js/composables/useIndexFilter.ts');
        $next = $w->artisan('mod:index-filter', ['name' => 'Knowledge:Document', '--fields' => ['status']])->assertSuccessful();
        expect($next->normalisedOutput())->toContain('will write 3 files', 'composable (kept, exists)')
            ->and($w->read('resources/js/composables/useIndexFilter.ts'))->toBe($shared);
    });
});
