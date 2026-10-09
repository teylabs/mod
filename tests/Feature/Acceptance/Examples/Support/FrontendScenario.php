<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Support;

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;
use Tey\Mod\Tests\Feature\Scaffolds\Support\TreeExamples;

final class FrontendScenario
{
    public static function setup(Workspace $w, string $stack = 'vue', bool $typescript = true): void
    {
        putenv('COLUMNS=72');
        config()->set('mod.layout', 'modules');
        $w->write('package.json', json_encode(['dependencies' => ['@inertiajs/'.($stack === 'vue' ? 'vue3' : 'react') => '*']], JSON_THROW_ON_ERROR));
        if ($typescript) {
            $w->write('tsconfig.json', '{}');
        }
        $w->write('app/Modules/Inventory/.gitkeep', '');
    }

    public static function source(string $id, string $label): string
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/Frontend/catalogue.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data[$id] as $step) {
            if ($step[0] === 'file' && $step[1] === $label) {
                return $step[2];
            }
        }
        throw new \LogicException("Missing catalogue fixture {$id}: {$label}");
    }

    public static function crud(Workspace $w, string $stack = 'vue'): void
    {
        Examples::setup($w);
        self::setup($w, $stack);
        Mod::scaffold('crud-pages', fn (Scaffold $s) => $s
            ->include('crud')
            ->makes('page', name: '{name}/Index', as: 'indexPage', stub: 'crud-index')
            ->makes('page', name: '{name}/Create', as: 'createPage', stub: 'crud-form')
            ->makes('page', name: '{name}/Edit', as: 'editPage', stub: 'crud-form')
            ->makes('page', name: '{name}/Show', as: 'showPage', stub: 'crud-show')
            ->makes('controller', name: '{name}Controller', stub: 'inertia-crud'));
        $w->write('stubs/mod.controller.inertia-crud.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {\n".self::source('F3', 'stubs/mod.controller.inertia-crud.stub (index and edit)')."\n}\n");
        $w->write('stubs/mod.page.crud-index.vue.stub', self::source('F3', 'stubs/mod.page.crud-index.vue.stub'));
        foreach (['crud-form', 'crud-show'] as $variant) {
            $w->write('stubs/mod.page.'.$variant.'.vue.stub', '<template><p>{{ name.studly }}</p></template>');
        }
        if ($stack === 'react') {
            foreach (['crud-index', 'crud-form', 'crud-show'] as $variant) {
                $w->write('stubs/mod.page.'.$variant.'.tsx.stub', "export default function {{ name.studly }}() { return <p>{{ name.studly }}</p>; }");
            }
        }
    }

    public static function tabs(Workspace $w): void
    {
        TreeExamples::setup($w);
        self::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/tabs-layout.vue.stub', self::source('F4', 'stubs/mod/@module/resources/js/components/tabs-layout.vue.stub (a template: mod:tabs-layout)'));
        $w->write('stubs/mod.page.tab-page.vue.stub', self::source('F4', 'stubs/mod.page.tab-page.vue.stub'));
        $w->write('stubs/mod.insert.tabs-action.stub', self::source('F4', 'stubs/mod.insert.tabs-action.stub'));
        Mod::scaffold('tab-page', fn (Scaffold $s) => $s
            ->asks('base', type: 'class')->asks('layout', type: 'file')->asks('tab')
            ->makes('view-model', name: '{name}{tab}ViewModel', as: 'page', stub: 'tab-page')
            ->makes('page', name: '{name}/{tab}', as: 'view', stub: 'tab-page'));
        Mod::scaffold('resource-tabs', fn (Scaffold $s) => $s
            ->asks('model', type: 'model', default: '{name}')
            ->asks('tabs', type: 'list', default: ['Overview', 'Details', 'Notes'])
            ->makes('view-model', name: 'Manage{name}ViewModel', as: 'base', stub: 'tabs-layout')
            ->makes('tabs-layout', name: '{name}Layout', as: 'layout')
            ->makes('controller', name: '{name}Controller', stub: 'tabs')
            ->part('tab', uses: 'tab-page', with: ['base' => '{{ base.fqcn }}', 'layout' => '{{ layout.path }}'], configure: fn (Part $p) => $p
                ->inserts(into: 'base', at: 'tabs', stub: 'tabs-entry')
                ->inserts(into: 'controller', at: 'actions', stub: 'tabs-action'))
            ->each('tabs', part: 'tab'));
    }
}
