<?php

use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Fixtures\Layouts;

it('is stable for equal presets built separately', function (string $layout) {
    expect(PresetFingerprint::of(Layouts::named($layout)))->toBe(PresetFingerprint::of(Layouts::named($layout)));
})->with(Layouts::NAMES);

it('differs between the five layouts', function () {
    $prints = array_map(fn (string $name): string => PresetFingerprint::of(Layouts::named($name)), Layouts::NAMES);

    expect(array_unique($prints))->toHaveCount(5);
});

it('changes when anything that decides ownership changes', function (Closure $change) {
    $definition = Layouts::definition('modules');

    expect(PresetFingerprint::of(CompiledLayout::fromArray($change($definition))))->not->toBe(PresetFingerprint::of(Layouts::modules()));
})->with([
    'segment' => [function (array $d) {
        $d['kinds']['provider']['segments'] = ['Modules', '{module}', 'Bootstrap'];

        return $d;
    }],
    'name policy' => [function (array $d) {
        $d['kinds']['provider']['name'] = 'as-given';

        return $d;
    }],
    'priority' => [function (array $d) {
        $d['kinds']['provider']['priority'] = 5;

        return $d;
    }],
    'excluded root' => [function (array $d) {
        $d['excluded'][] = ['namespace' => 'App\\Legacy\\', 'path' => 'app/Legacy'];

        return $d;
    }],
    'root path' => [function (array $d) {
        $d['roots']['app']['path'] = 'src';

        return $d;
    }],
    'new kind' => [function (array $d) {
        $d['kinds']['job'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Jobs']];

        return $d;
    }],
]);

it('fingerprints discovery definitions by kind, type and state', function () {
    $on = [DiscoveryDefinition::providers(), DiscoveryDefinition::commands()];

    expect(PresetFingerprint::ofDefinitions($on))->toBe(PresetFingerprint::ofDefinitions([DiscoveryDefinition::providers(), DiscoveryDefinition::commands()]))
        ->and(PresetFingerprint::ofDefinitions($on))->not->toBe(PresetFingerprint::ofDefinitions([DiscoveryDefinition::providers(), DiscoveryDefinition::commands()->disabled()]))
        ->and(PresetFingerprint::ofDefinitions($on))->not->toBe(PresetFingerprint::ofDefinitions([DiscoveryDefinition::providers(), DiscoveryDefinition::listeners('command')]));
});
