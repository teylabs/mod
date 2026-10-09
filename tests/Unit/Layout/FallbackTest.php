<?php

use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\MissingDimension;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Reverse\ReverseMapper;

function fallbackLayout(): Layout
{
    return (new Layout('fallback'))->path('app')->mounts('app', 'App\\', 'app', fn (Root $root) => $root
        ->generates('command', in: 'Areas/{area}/Console/Commands', ungrouped: 'Console/Commands', nested: true));
}

it('places a fallback only with no placement and keeps nested names', function () {
    $preset = fallbackLayout()->compile();
    expect(place($preset, 'command', 'SendReminders')->path())->toBe('app/Console/Commands/SendReminders.php')
        ->and(place($preset, 'command', 'Nested/SendReminders')->fqcn())->toBe('App\\Console\\Commands\\Nested\\SendReminders')
        ->and(place($preset, 'command', 'SendReminders', 'Billing')->path())->toBe('app/Areas/Billing/Console/Commands/SendReminders.php');
});

it('supports both the layout named argument and the kind chain', function () {
    $layout = ((new Layout('chain'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('command', in: '{area}/Commands', using: fn (FileType $kind) => $kind->ungrouped('Commands'));
    expect(place($layout->compile(), 'command', 'Run')->path())->toBe('app/Commands/Run.php');
});

it('does not hide missing or partially supplied dimensions', function () {
    $strict = ((new Layout('strict'))->path('app'))->mounts('app', 'App\\', 'app')->generates('command', in: '{area}/Commands')->compile();
    expect(fn () => place($strict, 'command', 'Run'))->toThrow(MissingDimension::class);
    $partial = ((new Layout('partial'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('command', in: '{area}/{group}/Commands', ungrouped: 'Commands')->compile();
    expect(fn () => place($partial, 'command', 'Run', 'Billing'))->toThrow(MissingDimension::class);
});

it('keeps optional placement semantics when no required dimension needs a fallback', function () {
    $preset = ((new Layout('optional'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('command', in: 'Commands/{area?}', ungrouped: 'Other')->compile();
    expect(place($preset, 'command', 'Run')->path())->toBe('app/Commands/Run.php');
});

it('rejects placeholders and paths outside the root in fallbacks', function (string $path) {
    $layout = fallbackLayout()->generates('command', ungrouped: $path);
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, 'invalid-fallback');
})->with(['{area}/Commands', 'Commands/{area?}', '../Commands', '/Commands', 'other:Commands']);

it('rejects colliding fallback patterns regardless of declaration order', function (bool $first) {
    $layout = (new Layout('collision'))->path('app');
    $layout->mounts('app', 'App\\', 'app');
    if ($first) {
        $layout->generates('other', in: 'Commands');
    }
    $layout->generates('command', in: '{area}/Commands', ungrouped: 'Commands');
    if (! $first) {
        $layout->generates('other', in: 'Commands');
    }
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, 'duplicate-placement-pattern');
})->with([true, false]);

it('reverse maps fallback classes and paths without a dimension value', function () {
    $preset = fallbackLayout()->compile();
    $mapper = new ReverseMapper($preset);
    foreach (['App\\Console\\Commands\\Run', 'app/Console/Commands/Run.php'] as $subject) {
        $match = str_ends_with($subject, '.php') ? $mapper->fromPath($subject) : $mapper->fromClass($subject);
        expect($match->isMatched())->toBeTrue()
            ->and($match->artifact?->kind->id)->toBe('command')
            ->and($match->artifact?->context->isEmpty())->toBeTrue();
    }
    expect($mapper->fromClass('App\\Areas\\Billing\\Console\\Commands\\Run')->artifact?->context)
        ->toEqual(PlacementContext::of(['area' => 'Billing']));
});

it('ranks a fallback below the regular placement rule', function () {
    $preset = ((new Layout('rank'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('command', in: '{area}/Commands', ungrouped: 'Commands')
        ->generates('other', in: 'Commands', priority: -1)->compile();
    $match = (new ReverseMapper($preset))->fromClass('App\\Commands\\Run');
    expect($match->isMatched())->toBeFalse(); // equal effective priorities remain ambiguous

    $preferred = ((new Layout('preferred'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('command', in: '{area}/Commands', ungrouped: 'Commands')
        ->generates('other', in: 'Commands', priority: 1)->compile();
    expect((new ReverseMapper($preferred))->fromClass('App\\Commands\\Run')->artifact?->kind->id)->toBe('other');
});

it('includes fallback locations in the discovery cache fingerprint', function () {
    expect(PresetFingerprint::of(fallbackLayout()->compile()))
        ->not->toBe(PresetFingerprint::of(fallbackLayout()->generates('command', ungrouped: 'Other')->compile()));
});
