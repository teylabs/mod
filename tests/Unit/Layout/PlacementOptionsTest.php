<?php

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\InvalidPreset;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Preset\PresetIssueCode;
use Tey\Mod\Preset\PresetValidator;

function placementOptionsLayoutError(Closure $define): string
{
    $layout = new Layout('options');
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
]);

it('names a camelCase placeholder in kebab-case', function () {
    $preset = (new Layout('kebab'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: '{subArea}/Models'))
        ->compile();

    expect($preset->placementOptions())->toBe(['subArea' => 'sub-area']);
});

it('renames the option of the only placeholder, or of a named one', function () {
    $registry = new LayoutRegistry;
    $registry->layout('modules')->placementOption('area');
    $registry->layout('slices')->placementOption('operation', '{slice}');

    expect($registry->compile('modules')->placementOptions())->toBe(['module' => 'area'])
        ->and($registry->compile('slices')->placementOptions())->toBe(['feature' => 'feature', 'slice' => 'operation']);
});

it('refuses an unnamed rename on a layout with several placeholders or none', function () {
    expect(placementOptionsLayoutError(fn (Layout $layout) => $layout
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: '{feature}/{slice}'))
        ->placementOption('area')))
        ->toContain("->placementOption('area')")
        ->toContain('name the one to rename ({feature}, {slice})');

    expect(placementOptionsLayoutError(fn (Layout $layout) => $layout
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: 'Models'))
        ->placementOption('area')))
        ->toContain('no placeholders');
});

it('refuses a rename of an unused placeholder, a malformed name and a duplicate option', function () {
    expect(placementOptionsLayoutError(fn (Layout $layout) => $layout
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: '{module}/Models'))
        ->placementOption('area', '{feature}')))
        ->toContain('placeholder {feature} is used by no kind');

    expect(placementOptionsLayoutError(fn (Layout $layout) => $layout
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: '{module}/Models'))
        ->placementOption('Bad_Name')))
        ->toContain('option must be a lowercase name');

    expect(placementOptionsLayoutError(fn (Layout $layout) => $layout
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('model', in: '{feature}/{slice}'))
        ->placementOption('feature', '{slice}')))
        ->toContain('--feature is already the option of {feature}; pick another name for {slice}');
});

it('validates placement options in the internal definition', function () {
    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'dimensions' => ['module'],
        'kinds' => ['model' => ['shape' => 'class', 'root' => 'app', 'segments' => ['{module}']]],
    ];

    expect(Preset::fromArray([...$definition, 'placement_options' => ['module' => 'area']])->placementOptions())->toBe(['module' => 'area'])
        ->and(fn () => Preset::fromArray([...$definition, 'placement_options' => ['feature' => 'area']]))->toThrow(InvalidPreset::class, 'names no declared dimension')
        ->and(fn () => Preset::fromArray([...$definition, 'placement_options' => 'area']))->toThrow(InvalidPreset::class, 'must be a map');
});

it('reports a placement option that collides with an option of the generating command', function () {
    $preset = (new Layout('collide'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('controller', in: 'Http/Controllers/{model}', suffix: 'Controller')
            ->kind('request', in: 'Http/Requests/{model}'))
        ->compile();

    $validator = new PresetValidator;
    $issues = $validator->placementOptionCollisions($preset, $preset->kind('controller'), ['in', 'model', 'm', 'force']);

    expect(array_keys($issues))->toBe(['model'])
        ->and($issues['model']->code)->toBe(PresetIssueCode::PlacementOptionCollision)
        ->and($issues['model']->subject)->toBe('mod:controller')
        ->and($issues['model']->message)->toContain("->placementOption('...', '{model}')")
        ->and($validator->placementOptionCollisions($preset, $preset->kind('request'), ['in', 'force']))->toBe([]);
});
