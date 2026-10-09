<?php

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Preset\PresetIssueCode;
use Tey\Mod\Preset\PresetValidator;

function placementOptionsLayoutError(Closure $define): string
{
    $layout = (new Layout('options'))->path('app');
    $define($layout);

    try {
        $layout->compile();
    } catch (InvalidLayout $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('The layout compiled.');
}

it('names one placement option per placeholder of each built-in', function (string $name, array $options) {
    expect((new LayoutRegistry)->compile($name)->placementOptions())->toBe($options);
})->with([
    'laravel' => ['laravel', []],
    'features' => ['features', ['feature' => 'feature']],
    'slices' => ['slices', ['feature' => 'feature', 'slice' => 'slice']],
    'type-first' => ['type-first', ['feature' => 'feature']],
    'modules' => ['modules', ['module' => 'module']],
    'ddd' => ['ddd', ['domain' => 'domain']],
]);

it('names a camelCase placeholder in kebab-case', function () {
    $preset = ((new Layout('kebab'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root->generates('model', in: '{subArea}/Models'))
        ->compile();

    expect($preset->placementOptions())->toBe(['subArea' => 'sub-area']);
});

it('names placement options from a redeclared group path', function () {
    $registry = new LayoutRegistry;
    $registry->layout('modules')->path('app/Modules/{area}');
    $registry->layout('slices')->path('app/{feature}/{operation}');
    expect($registry->compile('modules')->placementOptions())->toBe(['area' => 'area'])
        ->and($registry->compile('slices')->placementOptions())->toBe(['feature' => 'feature', 'operation' => 'operation']);
});

it('validates placement options in the internal definition', function () {
    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'dimensions' => ['module'],
        'kinds' => ['model' => ['shape' => 'class', 'root' => 'app', 'segments' => ['{module}']]],
    ];

    expect(CompiledLayout::fromArray([...$definition, 'placement_options' => ['module' => 'area']])->placementOptions())->toBe(['module' => 'area'])
        ->and(fn () => CompiledLayout::fromArray([...$definition, 'placement_options' => ['feature' => 'area']]))->toThrow(InvalidLayout::class, 'names no declared dimension')
        ->and(fn () => CompiledLayout::fromArray([...$definition, 'placement_options' => 'area']))->toThrow(InvalidLayout::class, 'must be a map');
});

it('reports a placement option that collides with an option of the generating command', function () {
    $preset = ((new Layout('collide'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('controller', in: 'Http/Controllers/{model}', suffix: 'Controller')
            ->generates('request', in: 'Http/Requests/{model}'))
        ->compile();

    $validator = new PresetValidator;
    $issues = $validator->placementOptionCollisions($preset, $preset->kind('controller'), ['in', 'model', 'm', 'force']);

    expect(array_keys($issues))->toBe(['model'])
        ->and($issues['model']->code)->toBe(PresetIssueCode::PlacementOptionCollision)
        ->and($issues['model']->subject)->toBe('mod:controller')
        ->and($issues['model']->message)->toContain("->path('app/{group}')")
        ->and($validator->placementOptionCollisions($preset, $preset->kind('request'), ['in', 'force']))->toBe([]);
});
