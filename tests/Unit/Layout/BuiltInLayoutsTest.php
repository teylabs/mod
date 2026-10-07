<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * The built-in layouts are the fixture layouts, rewritten with the public
 * builder: each compiles to the same roots, dimensions, kinds, rules,
 * relations and exclusions as its fixture (kinds compared by id; the builder
 * groups them by root).
 */

/**
 * @return array<string, mixed>
 */
function presetShape(Preset $preset): array
{
    $byId = function (array $items): array {
        ksort($items);

        return $items;
    };

    return [
        'roots' => $byId($preset->roots()),
        'dimensions' => $preset->dimensionNames(),
        'kinds' => $byId($preset->kinds()),
        'rules' => $byId($preset->rules()),
        'relations' => $byId($preset->relations()),
        'excluded' => $preset->excludedRoots(),
        'commands' => $preset->commandsEnabled(),
    ];
}

it('compiles each built-in layout to its fixture', function (string $builtIn, string $fixture) {
    expect(presetShape((new LayoutRegistry)->compile($builtIn)))->toEqual(presetShape(Layouts::named($fixture)));
})->with([
    'laravel (the original default configuration)' => ['laravel', 'laravel'],
    'features' => ['features', 'feature-first'],
    'slices' => ['slices', 'vertical-slices'],
    'type-first' => ['type-first', 'type-first'],
    'modules' => ['modules', 'modules'],
]);

it('gives every built-in layout its own registry copy', function () {
    $first = new LayoutRegistry;
    $first->layout('modules')->kind('job', in: 'Modules/{module}/Jobs');

    expect((new LayoutRegistry)->compile('modules')->hasKind('job'))->toBeFalse()
        ->and($first->compile('modules')->hasKind('job'))->toBeTrue();
});
