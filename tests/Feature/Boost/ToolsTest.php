<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Boost\Mcp\Boost;
use Laravel\Boost\Mcp\ToolRegistry;
use Laravel\Boost\Mcp\Tools\ApplicationInfo;
use Laravel\Mcp\Request;
use Tey\Mod\Boost\InventoryTool;
use Tey\Mod\Boost\PlanTool;
use Tey\Mod\Boost\ToolRegistrar;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('registers exactly two read-only tools alongside existing Boost includes', function () {
    $existing = ApplicationInfo::class;
    config()->set('boost.mcp.tools.include', [$existing]);
    ToolRegistrar::register(app());
    ToolRegistrar::register(app());
    expect(config('boost.mcp.tools.include'))->toBe([$existing, InventoryTool::class, PlanTool::class]);
    ToolRegistry::clearCache();
    foreach ([InventoryTool::class => 'mod-inventory', PlanTool::class => 'mod-plan'] as $class => $name) {
        expect(ToolRegistry::isToolAllowed($class))->toBeTrue();
        $tool = app($class);
        expect($tool->toArray()['name'])->toBe($name)
            ->and($tool->toArray()['annotations']['readOnlyHint'])->toBeTrue();
    }
    $server = (new ReflectionClass(Boost::class))->newInstanceWithoutConstructor();
    $tools = (new ReflectionMethod($server, 'discoverTools'))->invoke($server);
    expect($tools)->toContain(InventoryTool::class, PlanTool::class);
    ToolRegistry::clearCache();
});

it('describes positional arguments and flag tokens in the plan tool schema', function () {
    $schema = app(PlanTool::class)->toArray()['inputSchema'];
    expect($schema['required'])->toBe(['command', 'arguments'])
        ->and($schema['properties']['arguments']['type'])->toBe('array')
        ->and($schema['properties']['arguments']['items']['type'])->toBe('string');
});

it('forces a noninteractive JSON preview even when flags try to disable it', function (array $arguments) {
    Workspace::run(null, function (Workspace $w) use ($arguments) {
        config()->set('mod.layout', 'modules');
        $before = Scenario::bytes($w);
        $tool = app(PlanTool::class);
        $data = Scenario::data($tool->handle(new Request(['command' => 'mod:model', 'arguments' => $arguments])), $tool);
        expect($data['files'][0]['path'])->toBe('app/Modules/Inventory/Models/Widget.php')
            ->and(Scenario::bytes($w))->toBe($before);
    });
})->with([
    [['Inventory:Widget', '--force']],
    [['Inventory:Widget', '--dry-run=0', '--json=0', '--no-interaction=0']],
]);

it('returns missing answers as plan warnings without prompting', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('needs-answer', fn (Scaffold $s) => $s->asks('label')->makes('model'));
        $tool = app(PlanTool::class);
        $data = Scenario::data($tool->handle(new Request(['command' => 'mod:needs-answer', 'arguments' => ['Inventory:Widget']])), $tool);
        expect($data['would_write'])->toBeFalse()
            ->and($data['warnings'][0]['message'])->toContain('--label=<value>')
            ->and($w->files())->toBe([]);
    });
});

it('refuses unsafe or malformed requests before executing a command', function (array $request) {
    Workspace::run(null, function (Workspace $w) use ($request) {
        config()->set('mod.layout', 'modules');
        $before = Scenario::bytes($w);
        $tool = app(PlanTool::class);
        $response = $tool->handle(new Request($request));
        expect($response->isError())->toBeTrue()
            ->and($response->content()->toTool($tool)['text'])->toContain('mod-plan')
            ->and(Scenario::bytes($w))->toBe($before);
    });
})->with([
    [['command' => 'migrate', 'arguments' => []]],
    [['command' => 'mod:cache', 'arguments' => []]],
    [['command' => 'mod:clear', 'arguments' => []]],
    [['command' => 'mod:list', 'arguments' => []]],
    [['command' => 'mod:missing', 'arguments' => []]],
    [['command' => 'mod:model', 'arguments' => ['Inventory:Widget', '--not-an-option']]],
    [['command' => 'mod:model', 'arguments' => ['Inventory:Widget', 'extra']]],
    [['command' => 'mod:model', 'arguments' => ['name' => 'Inventory:Widget']]],
    [['command' => 'mod:model', 'arguments' => [false]]],
    [['command' => 3, 'arguments' => []]],
    [['command' => 'mod:model']],
]);

it('converts invalid inventory layouts into a tool error', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'missing-layout');
        $tool = app(InventoryTool::class);
        expect($tool->handle(new Request)->isError())->toBeTrue();
    });
});

it('uses the existing preview for every registered writer in every built-in layout', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        $tool = app(PlanTool::class);
        $before = Scenario::bytes($w);
        foreach (Artisan::all() as $command) {
            $name = $command->getName();
            $definition = $command->getDefinition();
            if ($name === null || ! str_starts_with($name, 'mod:') || ! $definition->hasOption('dry-run') || ! $definition->hasOption('json')) {
                continue;
            }
            $arguments = [];
            foreach ($definition->getArguments() as $argument) {
                $value = match ($argument->getName()) {
                    'command' => null,
                    'stack' => 'inertia',
                    'type' => 'class',
                    'path' => 'tool',
                    'module' => $layout === 'slices' ? 'Inventory/Index' : 'Inventory',
                    'name' => match ($layout) {
                        'modules', 'ddd', 'features' => 'Inventory:Widget',
                        'slices' => 'Inventory/Index:Widget',
                        default => 'Widget',
                    },
                    default => null,
                };
                if ($value !== null) {
                    $arguments[] = $value;
                }
            }
            $data = Scenario::data($tool->handle(new Request(['command' => $name, 'arguments' => $arguments])), $tool);
            expect($data['command'])->toBe($name)
                ->and(JsonSchema::errors($data, json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR)))->toBe([], $name)
                ->and(Scenario::bytes($w))->toBe($before, $name);
        }
    });
})->with(['laravel', 'modules', 'features', 'slices', 'type-first', 'ddd']);
