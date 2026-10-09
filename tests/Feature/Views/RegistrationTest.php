<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\FileViewFinder;
use Pest\TestSuite;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;
use Tey\Mod\Views\ViewDirectories;
use Tey\Mod\Views\ViewNamespaceRegistrar;

function moduleViewsTestCase(): TestCase
{
    $case = TestSuite::getInstance()->test;
    if (! $case instanceof TestCase) {
        throw new RuntimeException('Expected the package Testbench case.');
    }

    return $case;
}

function moduleViewsFinder(): FileViewFinder
{
    $finder = View::getFinder();
    if (! $finder instanceof FileViewFinder) {
        throw new RuntimeException('Expected Laravel file view finder.');
    }

    return $finder;
}

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
        moduleViewsTestCase()->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('view.paths', [$root->path('resources/views')]);
            $app->make('config')->set('view.compiled', $root->path('storage/framework/views'));
        });
        expect(View::exists('inventory::show'))->toBeTrue()->and(View::exists('agent-tools::show'))->toBeTrue()
            ->and(array_keys(moduleViewsFinder()->getHints()))->not->toContain('empty')
            ->and(View::file(moduleViewsFinder()->find('inventory::show'))->render())->toContain('<b>Stock</b>');
        expect(app(Kernel::class)->call('view:cache'))->toBe(0)
            // view:cache compiles Finder's real path, whose separators differ on Windows.
            ->and(is_file(Blade::getCompiledPath((string) realpath($root->path('app/Modules/AgentTools/resources/views/show.blade.php')))))->toBeTrue();
        $inventory = json_decode(app(Kernel::class)->call('mod:list', ['--json' => true]) === 0 ? app(Kernel::class)->output() : '{}', true, flags: JSON_THROW_ON_ERROR);
        expect($inventory['views'][0])->toBe(['group' => 'AgentTools', 'namespace' => 'agent-tools', 'path' => 'app/Modules/AgentTools/resources/views', 'components' => [['path' => 'app/Modules/AgentTools/resources/views/components/stock-badge.blade.php', 'tag' => 'x-agent-tools::stock-badge']]]);
    });
});

it('warns and preserves Laravel and already registered namespaces', function (string $group, string $namespace) {
    OwnedAppRoot::using(function (OwnedAppRoot $root) use ($group, $namespace) {
        mkdir($root->path('app/Modules/'.$group.'/resources/views'), 0700, true);
        file_put_contents($root->path('app/Modules/'.$group.'/resources/views/show.blade.php'), 'module');
        $app = moduleViewsTestCase()->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'modules');
        });
        $warnings = [];
        $app->make('log')->listen(function ($event) use (&$warnings) {
            $warnings[] = $event->message;
        });
        View::replaceNamespace($namespace, '/original');
        ViewNamespaceRegistrar::register($app);
        expect(moduleViewsFinder()->getHints()[$namespace])->toBe(['/original'])
            ->and(implode('\n', $warnings))->toContain($group, $namespace);
    });
})->with([['Mail', 'mail'], ['Notifications', 'notifications'], ['Pagination', 'pagination'], ['AgentTools', 'agent-tools']]);

it('registers type-first kebab group folders without exposing other app views', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        foreach (['resources/views/inventory/components', 'resources/views/agent-tools', 'app/Models/Inventory', 'app/Models/AgentTools'] as $directory) {
            mkdir($root->path($directory), 0700, true);
        }
        file_put_contents($root->path('resources/views/inventory/show.blade.php'), '<x-inventory::stock-badge />');
        file_put_contents($root->path('resources/views/inventory/components/stock-badge.blade.php'), '<b>Stock</b>');
        file_put_contents($root->path('resources/views/private.blade.php'), 'private');
        moduleViewsTestCase()->bootApplicationUsing(function (Application $app) use ($root) {
            $app->setBasePath($root->path);
            $app->make('config')->set('mod.layout', 'type-first');
        });
        expect(View::file(moduleViewsFinder()->find('inventory::show'))->render())->toContain('<b>Stock</b>')
            ->and(View::exists('inventory::private'))->toBeFalse()
            ->and(moduleViewsFinder()->getHints()['agent-tools'])->toBe([Path::resolve($root->path, 'resources/views/agent-tools')]);
        $entries = (new ViewDirectories(app(CompiledLayout::class), $root->path))->entries();
        expect(array_column($entries, 'group'))->toBe([null, 'AgentTools', 'Inventory']);
    });
});

it('registers class components with their constructor and qualified view', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $root) {
        $group = 'G'.bin2hex(random_bytes(4));
        mkdir($root->path('app/Modules/'.$group.'/View/Components'), 0700, true);
        mkdir($root->path('app/Modules/'.$group.'/resources/views/components'), 0700, true);
        $class = 'App\\Modules\\'.$group.'\\View\\Components\\StockBadge';
        $file = $root->path('app/Modules/'.$group.'/View/Components/StockBadge.php');
        $namespace = Str::kebab($group);
        file_put_contents($file, '<?php namespace App\\Modules\\'.$group.'\\View\\Components; class StockBadge extends \\Illuminate\\View\\Component { public function __construct(public string $label) {} public function render() { return view("'.$namespace.'::components.stock-badge"); } }');
        file_put_contents($root->path('app/Modules/'.$group.'/resources/views/components/stock-badge.blade.php'), '<b>{{ $label }}</b>');
        $load = static function (string $name) use ($class, $file) {
            if ($name === $class) {
                require $file;
            }
        };
        spl_autoload_register($load);
        try {
            moduleViewsTestCase()->bootApplicationUsing(function (Application $app) use ($root) {
                $app->setBasePath($root->path);
                $app->make('config')->set('mod.layout', 'modules');
            });
            expect(Blade::render('<x-'.$namespace.'::stock-badge label="Class data" />'))->toContain('<b>Class data</b>');
        } finally {
            spl_autoload_unregister($load);
        }
    });
});
