<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Tey\Mod\Tests\Support\OwnedAppRoot;

it('registers only groups with views at boot even with discovery disabled and caches their components', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        foreach (['Inventory', 'AgentTools'] as $group) {
            mkdir($root->path('app/Modules/'.$group.'/resources/views/components'), 0700, true);
            file_put_contents($root->path('app/Modules/'.$group.'/resources/views/components/stock-badge.blade.php'), '<b>Stock</b>');
            file_put_contents($root->path('app/Modules/'.$group.'/resources/views/show.blade.php'), '<x-'.($group === 'Inventory' ? 'inventory' : 'agent-tools').'::stock-badge />');
        }
        mkdir($root->path('app/Modules/Empty/Models'), 0700, true);
        mkdir($root->path('resources/views'), 0700, true);
        mkdir($root->path('storage/framework/views'), 0700, true);
        $this->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('view.paths', [$root->path('resources/views')]);
            $app->make('config')->set('view.compiled', $root->path('storage/framework/views'));
        });
        expect(View::exists('inventory::show'))->toBeTrue()->and(View::exists('agent-tools::show'))->toBeTrue()
            ->and(array_keys(View::getFinder()->getHints()))->not->toContain('empty')
            ->and(View::make('inventory::show')->render())->toContain('<b>Stock</b>');
        expect(app(Kernel::class)->call('view:cache'))->toBe(0)
            ->and(is_file(Blade::getCompiledPath($root->path('app/Modules/AgentTools/resources/views/show.blade.php'))))->toBeTrue();
        $inventory = json_decode(app(Kernel::class)->call('mod:list', ['--json' => true]) === 0 ? app(Kernel::class)->output() : '{}', true, flags: JSON_THROW_ON_ERROR);
        expect($inventory['views'][0])->toBe(['group' => 'AgentTools', 'namespace' => 'agent-tools', 'path' => 'app/Modules/AgentTools/resources/views', 'components' => [['path' => 'app/Modules/AgentTools/resources/views/components/stock-badge.blade.php', 'tag' => 'x-agent-tools::stock-badge']]]);
    });
});

it('warns and preserves Laravel and already registered namespaces', function (string $group, string $namespace) {
    OwnedAppRoot::using(function (OwnedAppRoot $root) use ($group, $namespace) {
        mkdir($root->path('app/Modules/'.$group.'/resources/views'), 0700, true);
        file_put_contents($root->path('app/Modules/'.$group.'/resources/views/show.blade.php'), 'module');
        $app = $this->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
        });
        $warnings = [];
        $app->make('log')->listen(function ($event) use (&$warnings) { $warnings[] = $event->message; });
        View::addNamespace($namespace, '/original');
        Tey\Mod\Views\ViewNamespaceRegistrar::register($app);
        expect(View::getFinder()->getHints()[$namespace])->toBe(['/original'])
            ->and(implode('\n', $warnings))->toContain($group, $namespace);
    });
})->with([['Mail', 'mail'], ['Notifications', 'notifications'], ['Pagination', 'pagination'], ['AgentTools', 'agent-tools']]);
