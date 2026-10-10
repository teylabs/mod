<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R3 derives the cluster from the compiled layout including explicit frontend opt-in', function (string $layout, bool $frontend) {
    Workspace::run(null, function (Workspace $w) use ($layout, $frontend) {
        S::setup($w);
        config()->set('mod.layout', $layout);
        if ($layout === 'ddd' && $frontend) {
            Mod::layout('ddd')->frontend(pages: 'app/Modules/{domain}/resources/js/pages', components: 'app/Modules/{domain}/resources/js/components', css: 'app/Modules/{domain}/resources/css', views: 'app/Modules/{domain}/resources/views', pageName: '{domain}::{path}');
        }
        Mod::scaffold('crud', function (Scaffold $s) use ($frontend) {
            $s->makes('model')->makes('resource', name: '{name}Resource', as: 'resource')->makes('policy')->makes('controller')->makes('request', name: 'Store{name}Request', as: 'store')->makes('request', name: 'Update{name}Request', as: 'update');
            if ($frontend) {
                foreach (['Index', 'Create', 'Edit', 'Show'] as $page) {
                    $s->makes('page', name: '{name}/'.$page, as: strtolower($page));
                }
            }
        });
        app()->forgetInstance(CompiledLayout::class);
        $compiled = app(CompiledLayout::class);
        $context = PlacementContext::fromOption('Inventory', $compiled);
        $members = ['model' => 'Widget', 'resource' => 'WidgetResource', 'policy' => 'Widget', 'controller' => 'Widget', 'store' => 'StoreWidgetRequest', 'update' => 'UpdateWidgetRequest'];
        $expected = [];
        foreach ($members as $alias => $name) {
            $type = in_array($alias, ['store', 'update']) ? 'request' : $alias;
            $old = $compiled->place($type, $name, $context);
            $new = $compiled->place($type, str_replace('Widget', 'Gadget', $name), PlacementContext::fromOption('Catalog', $compiled));
            $w->write($old->path(), "<?php\nnamespace ".$old->namespace().";\nclass ".class_basename($old->fqcn())." {}\n");
            $expected[$old->path()] = $new->path();
        }
        if ($frontend) {
            foreach (['Index', 'Create', 'Edit', 'Show'] as $page) {
                $old = $compiled->place('page', 'Widget/'.$page, $context);
                $new = $compiled->place('page', 'Gadget/'.$page, PlacementContext::fromOption('Catalog', $compiled));
                $w->write($old->path(), '<template>Edited body</template>');
                $expected[$old->path()] = $new->path();
            }
        }
        $w->write($layout === 'ddd' ? 'src/Domain/Catalog/Models/.gitkeep' : 'app/Modules/Catalog/Models/.gitkeep', '');
        if ($layout === 'ddd') {
            unlink($w->root->path('app/Modules/Inventory/Models/Widget.php'));
        }
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'crud', 'new' => 'Catalog:Gadget']);
        $expected = [];
        $domain = $layout === 'ddd' ? 'src/Domain' : 'app/Modules';
        $http = $layout === 'ddd' ? '' : 'Http/';
        foreach (['Models/Widget.php' => 'Models/Gadget.php', 'Policies/WidgetPolicy.php' => 'Policies/GadgetPolicy.php'] as $from => $to) {
            $expected[$domain.'/Inventory/'.$from] = $domain.'/Catalog/'.$to;
        }
        $expected[$domain.'/Inventory/'.$http.'Resources/WidgetResource.php'] = $domain.'/Catalog/'.$http.'Resources/GadgetResource.php';
        foreach (['Controllers/WidgetController.php' => 'Controllers/GadgetController.php', 'Requests/StoreWidgetRequest.php' => 'Requests/StoreGadgetRequest.php', 'Requests/UpdateWidgetRequest.php' => 'Requests/UpdateGadgetRequest.php'] as $from => $to) {
            $expected['app/Modules/Inventory/'.$http.$from] = 'app/Modules/Catalog/'.$http.$to;
        }
        if ($frontend) {
            foreach (['Index', 'Create', 'Edit', 'Show'] as $page) {
                $expected['app/Modules/Inventory/resources/js/pages/Widget/'.$page.'.vue'] = 'app/Modules/Catalog/resources/js/pages/Gadget/'.$page.'.vue';
            }
        }
        ksort($expected);
        expect($data['warnings'])->toBe([])->and(array_column($data['moves'], 'to', 'from'))->toBe($expected)
            ->and(count($data['moves']))->toBe($frontend ? 10 : 6);
        S::apply($w, ['--scaffold' => 'crud', 'new' => 'Catalog:Gadget']);
    });
})->with([['modules', true], ['ddd', true], ['ddd', false]]);
