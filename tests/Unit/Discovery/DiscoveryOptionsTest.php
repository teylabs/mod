<?php

use Tey\Mod\Discovery\CacheMismatchPolicy;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Fixtures\Layouts;

/**
 * @param  list<DiscoveryDefinition>  $definitions
 * @return list<string>
 */
function describeDefinitions(array $definitions): array
{
    return array_map(fn (DiscoveryDefinition $definition): string => $definition->identity(), $definitions);
}

it('discovers the preset kinds named after a type, and migration directories, by default', function (string $layout, array $expected) {
    expect(describeDefinitions((new DiscoveryOptions)->definitionsFor(Layouts::named($layout))))->toBe($expected);
})->with([
    'ordinary' => ['ordinary', ['command:command:on', 'listener:listener:on', 'migration:directory:on', 'model:factory:on', 'model:policy:on', 'provider:provider:on']],
    'feature-first' => ['feature-first', ['command:command:on', 'migration:directory:on', 'model:factory:on', 'model:policy:on', 'provider:provider:on']],
    // No model -> policy relation in this layout: no policy pairs.
    'vertical-slices' => ['vertical-slices', ['command:command:on', 'migration:directory:on', 'model:factory:on']],
    'type-first' => ['type-first', ['migration:directory:on', 'model:factory:on', 'model:policy:on']],
    'modules' => ['modules', ['migration:directory:on', 'model:factory:on', 'model:policy:on', 'provider:provider:on']],
]);

it('merges host settings over the defaults', function () {
    $options = DiscoveryOptions::fromConfig(['kinds' => ['provider' => false, 'event' => 'listener']]);

    expect(describeDefinitions($options->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:on', 'event:listener:on', 'listener:listener:on', 'migration:directory:on', 'model:factory:on', 'model:policy:on', 'provider:provider:off']);
});

it('disables every kind when discovery is off', function () {
    expect(describeDefinitions(DiscoveryOptions::fromConfig(['enabled' => false])->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:off', 'listener:listener:off', 'migration:directory:off', 'model:factory:off', 'model:policy:off', 'provider:provider:off']);
});

it('turns factory and policy pairs off with their switches', function () {
    expect(describeDefinitions(DiscoveryOptions::fromConfig(['factories' => false])->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:on', 'listener:listener:on', 'migration:directory:on', 'model:factory:off', 'model:policy:on', 'provider:provider:on'])
        ->and(describeDefinitions(DiscoveryOptions::fromConfig(['policies' => false])->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:on', 'listener:listener:on', 'migration:directory:on', 'model:factory:on', 'model:policy:off', 'provider:provider:on'])
        ->and(DiscoveryOptions::fromConfig([])->factories)->toBeTrue()
        ->and(DiscoveryOptions::fromConfig([])->policies)->toBeTrue()
        ->and(DiscoveryOptions::fromConfig(['factories' => false])->withCandidates(fn () => [])->factories)->toBeFalse();
});

it('reads the defaults from an empty config', function () {
    $options = DiscoveryOptions::fromConfig([]);

    expect($options->enabled)->toBeTrue()
        ->and($options->kinds)->toBe([])
        ->and($options->cachePath)->toBe('bootstrap/cache/mod-discovery.php')
        ->and($options->onStaleCache)->toBe(CacheMismatchPolicy::Scan)
        ->and(DiscoveryOptions::fromConfig(['on_stale_cache' => 'fail'])->onStaleCache)->toBe(CacheMismatchPolicy::Fail)
        ->and(DiscoveryOptions::fromConfig(['on_stale_cache' => 'scan'])->onStaleCache)->toBe(CacheMismatchPolicy::Scan)
        ->and(DiscoveryOptions::fromConfig(['kinds' => ['x' => DiscoveryType::Command]])->kinds)->toBe(['x' => DiscoveryType::Command]);
});

it('rejects invalid config with the offending key', function (array $config, string $message) {
    expect(fn () => DiscoveryOptions::fromConfig($config))->toThrow(InvalidDiscoveryConfig::class, $message);
})->with([
    [['enabled' => 'yes'], '[mod.discovery.enabled]'],
    [['kinds' => 'provider'], '[mod.discovery.kinds]'],
    [['kinds' => ['provider']], 'keys must be kind ids'],
    [['kinds' => ['provider' => 'middleware']], '[mod.discovery.kinds.provider]: expected provider, command, listener, subscriber, directory or false'],
    [['cache' => ''], '[mod.discovery.cache]'],
    [['on_stale_cache' => 'rebuild'], '[mod.discovery.on_stale_cache]'],
    [['factories' => 'yes'], '[mod.discovery.factories]: expected a boolean'],
    [['policies' => 1], '[mod.discovery.policies]: expected a boolean'],
    // Factory and policy pairs come from the switches, not from a kind mapping.
    [['kinds' => ['model' => 'factory']], '[mod.discovery.kinds.model]: expected provider, command, listener, subscriber, directory or false'],
]);

it('rejects kinds the preset cannot discover', function (array $kinds, string $message) {
    expect(fn () => DiscoveryOptions::fromConfig(['kinds' => $kinds])->definitionsFor(Layouts::ordinary()))
        ->toThrow(InvalidDiscoveryConfig::class, $message);
})->with([
    [['handler' => 'command'], '[mod.discovery.kinds.handler]: the active layout has no [handler] kind'],
    [['migration' => 'listener'], 'only class kinds can be discovered'],
]);

it('names the kinds a layout has when the config maps one it lacks', function (string $layout) {
    $preset = (new LayoutRegistry)->compile($layout);

    expect(fn () => DiscoveryOptions::fromConfig(['kinds' => ['console' => 'command']])->definitionsFor($preset))
        ->toThrow(InvalidDiscoveryConfig::class, 'Invalid discovery configuration [mod.discovery.kinds.console]: the active layout has no [console] kind. Map one of its kind ids: ');

    try {
        DiscoveryOptions::fromConfig(['kinds' => ['console' => 'command']])->definitionsFor($preset);
    } catch (InvalidDiscoveryConfig $exception) {
        foreach (array_keys($preset->kinds()) as $kind) {
            expect($exception->getMessage())->toContain($kind);
        }
    }
})->with(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd']);
