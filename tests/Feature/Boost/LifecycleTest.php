<?php

use Symfony\Component\Process\Process;
use Tey\Mod\Boost\InventoryTool;
use Tey\Mod\Boost\PlanTool;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('appears and executes through real Boost in fresh applications with either provider order', function (bool $boostFirst) {
    Workspace::run(null, function (Workspace $w) use ($boostFirst) {
        Scenario::application($w, $boostFirst);
        $script = <<<'SCRIPT'
require $argv[1];
$app = require $argv[2].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$executor = $app->make(\Laravel\Boost\Mcp\ToolExecutor::class);
$inventory = $executor->execute(\Tey\Mod\Boost\InventoryTool::class);
$plan = $executor->execute(\Tey\Mod\Boost\PlanTool::class, ['command' => 'mod:model', 'arguments' => ['Inventory:Widget']]);
echo json_encode([
    'registered' => config('boost.mcp.tools.include'),
    'inventory_error' => $inventory->isError(),
    'inventory' => (string) $inventory->content(),
    'plan_error' => $plan->isError(),
    'plan' => (string) $plan->content(),
]);
SCRIPT;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3).'/vendor/autoload.php', $w->root->path], $w->root->path);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['registered'])->toBe([InventoryTool::class, PlanTool::class])
            ->and($data['inventory_error'])->toBeFalse($data['inventory'])
            ->and($data['plan_error'])->toBeFalse($data['plan']);
        $inventory = json_decode($data['inventory'], true, flags: JSON_THROW_ON_ERROR);
        $plan = json_decode($data['plan'], true, flags: JSON_THROW_ON_ERROR);
        expect($inventory['layout'])->toBe('modules')
            ->and($plan['files'][0]['path'])->toBe('app/Modules/Inventory/Models/Widget.php')
            ->and($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse();
    });
})->with([false, true]);

it('boots and lists with no Boost or MCP classes available without registering anything', function () {
    Workspace::run(null, function (Workspace $w) {
        Scenario::application($w);
        $w->write('bootstrap/providers.php', '<?php return [\\Tey\\Mod\\ModServiceProvider::class];');
        $script = <<<'SCRIPT'
$original = require $argv[1];
// A clean loader excludes optional packages, including optimized classmap entries.
$loader = new \Composer\Autoload\ClassLoader;
foreach ($original->getPrefixesPsr4() as $prefix => $paths) {
    if (! str_starts_with($prefix, 'Laravel\\Boost\\') && ! str_starts_with($prefix, 'Laravel\\Mcp\\')) {
        $loader->setPsr4($prefix, $paths);
    }
}
$loader->addClassMap(array_filter($original->getClassMap(), fn ($class) => ! str_starts_with($class, 'Laravel\\Boost\\') && ! str_starts_with($class, 'Laravel\\Mcp\\'), ARRAY_FILTER_USE_KEY));
$original->unregister();
$loader->register();
$app = require $argv[2].'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('mod:list', ['--json' => true]);
echo json_encode([
    'boost' => class_exists(\Laravel\Boost\Mcp\Boost::class),
    'mcp' => class_exists(\Laravel\Mcp\Server\Tool::class),
    'includes' => config('boost.mcp.tools.include'),
    'inventory' => json_decode($kernel->output(), true),
]);
SCRIPT;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3).'/vendor/autoload.php', $w->root->path], $w->root->path);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['boost'])->toBeFalse()->and($data['mcp'])->toBeFalse()
            ->and($data['includes'])->toBeNull()
            ->and($data['inventory']['layout'])->toBe('modules');
    });
});
