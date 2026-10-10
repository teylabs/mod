<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support;

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

final class ExecutionScenario
{
    /** @return array<string, string> */
    public static function crud(Workspace $w, string $target = 'Inventory:Gadget', bool $appPages = false): array
    {
        RenameScenario::setup($w);
        if ($appPages) {
            Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
            app()->forgetInstance(CompiledLayout::class);
        }
        Mod::scaffold('crud', function (Scaffold $s): void {
            $s->makes('model')->makes('controller', name: '{name}Controller', as: 'controller')->makes('request', name: 'Store{name}Request', as: 'store')->makes('request', name: 'Update{name}Request', as: 'update')->makes('resource', name: '{name}Resource', as: 'resource')->makes('policy', name: '{name}Policy', as: 'policy');
            foreach (['Index', 'Create', 'Edit', 'Show'] as $page) {
                $s->makes('page', name: '{name}/'.$page, as: strtolower($page));
            }
        });
        $layout = app(CompiledLayout::class);
        [$group, $name] = explode(':', $target);
        $moves = [];
        foreach (['model' => 'Widget', 'controller' => 'WidgetController', 'store' => 'StoreWidgetRequest', 'update' => 'UpdateWidgetRequest', 'resource' => 'WidgetResource', 'policy' => 'WidgetPolicy', 'index' => 'Widget/Index', 'create' => 'Widget/Create', 'edit' => 'Widget/Edit', 'show' => 'Widget/Show'] as $alias => $oldName) {
            $type = in_array($alias, ['store', 'update']) ? 'request' : (in_array($alias, ['index', 'create', 'edit', 'show']) ? 'page' : $alias);
            $old = $layout->place($type, $oldName, PlacementContext::fromOption('Inventory', $layout));
            $new = $layout->place($type, str_replace('Widget', $name, $oldName), PlacementContext::fromOption($group, $layout));
            $body = $old->fqcn() === null ? '<template>Widget user copy</template>' : "<?php\nnamespace ".$old->namespace().";\nclass ".class_basename($old->fqcn())." { /* edited body */ }\n";
            if ($alias === 'controller') {
                $identity = $appPages ? 'Inventory/Widget/Show' : 'Inventory::Widget/Show';
                $body = "<?php\nnamespace ".$old->namespace().";\nuse App\\Modules\\Inventory\\Models\\Widget;\nuse Inertia\\Inertia;\nclass WidgetController {\n    public function show(Widget \$widget) { return Inertia::render('{$identity}', ['item' => \$widget]); }\n}\n";
            }
            $w->write($old->path(), $body);
            $moves[$old->path()] = $new->path();
        }
        $w->write('app/Modules/Orders/Actions/ReserveStock.php', "<?php\nuse App\\Modules\\Inventory\\Models\\Widget as StockItem;\nreturn StockItem::query();\n");
        $w->write('app/Modules/Inventory/InventoryServiceProvider.php', '<?php // retained provider');
        $w->write('app/Modules/Inventory/routes/web.php', '<?php // retained routes');
        RenameScenario::commit($w);
        ksort($moves);

        return $moves;
    }
}
