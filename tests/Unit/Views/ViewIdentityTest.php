<?php

use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Views\ViewIdentity;

it('exposes view names and component tags for plain-file members', function () {
    $view = new ViewIdentity('AgentTools', 'widgets.show', 'app/views/widgets/show.blade.php');
    expect($view->name())->toBe('agent-tools::widgets.show')->and($view->path())->toBe('app/views/widgets/show.blade.php');
    $component = new ViewIdentity('Inventory', 'components.stock-badge', 'app/views/components/stock-badge.blade.php');
    expect($component->name())->toBe('inventory::components.stock-badge')->and($component->tag())->toBe('x-inventory::stock-badge');
    expect((new ViewIdentity(null, 'components.stock-badge', 'resources/views/components/stock-badge.blade.php'))->tag())->toBe('x-stock-badge');
});

it('places a file type through placeholders in its plain-file root', function () {
    $layout = (new Layout('areas'))->path('app/Areas/{area}')
        ->mounts('app', 'App\\', 'app')->generates('model', in: '@area/Models')
        ->mounts('views', null, 'app/Areas/{area}/ui/views', fn (Root $root) => $root->generates('view', in: '', using: fn (FileType $type) => $type->file()));
    $compiled = $layout->compile();
    expect(place($compiled, 'view', 'show', 'Inventory')->path())->toBe('app/Areas/Inventory/ui/views/show.php');
});
