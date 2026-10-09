<?php

use Tey\Mod\Views\ViewIdentity;

it('exposes view names and component tags for plain-file members', function () {
    $view = new ViewIdentity('AgentTools', 'widgets.show', 'app/views/widgets/show.blade.php');
    expect($view->name())->toBe('agent-tools::widgets.show')->and($view->path())->toBe('app/views/widgets/show.blade.php');
    $component = new ViewIdentity('Inventory', 'components.stock-badge', 'app/views/components/stock-badge.blade.php');
    expect($component->name())->toBe('inventory::components.stock-badge')->and($component->tag())->toBe('x-inventory::stock-badge');
    expect((new ViewIdentity(null, 'components.stock-badge', 'resources/views/components/stock-badge.blade.php'))->tag())->toBe('x-stock-badge');
});
