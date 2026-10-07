<?php

use Tey\Mod\Discovery\CacheMismatchPolicy;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Tests\Fixtures\Layouts;

/**
 * @param  list<DiscoveryDefinition>  $definitions
 * @return list<string>
 */
function describeDefinitions(array $definitions): array
{
    return array_map(fn (DiscoveryDefinition $definition): string => $definition->identity(), $definitions);
}

it('discovers the preset kinds named after a type by default', function (string $layout, array $expected) {
    expect(describeDefinitions((new DiscoveryOptions)->definitionsFor(Layouts::named($layout))))->toBe($expected);
})->with([
    'ordinary' => ['ordinary', ['command:command:on', 'listener:listener:on', 'provider:provider:on']],
    'feature-first' => ['feature-first', ['command:command:on', 'provider:provider:on']],
    'vertical-slices' => ['vertical-slices', ['command:command:on']],
    'type-first' => ['type-first', []],
    'modules' => ['modules', ['provider:provider:on']],
]);

it('merges host settings over the defaults', function () {
    $options = DiscoveryOptions::fromConfig(['kinds' => ['provider' => false, 'event' => 'listener']]);

    expect(describeDefinitions($options->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:on', 'event:listener:on', 'listener:listener:on', 'provider:provider:off']);
});

it('disables every kind when discovery is off', function () {
    expect(describeDefinitions(DiscoveryOptions::fromConfig(['enabled' => false])->definitionsFor(Layouts::ordinary())))
        ->toBe(['command:command:off', 'listener:listener:off', 'provider:provider:off']);
});

it('reads the defaults from an empty config', function () {
    $options = DiscoveryOptions::fromConfig([]);

    expect($options->enabled)->toBeTrue()
        ->and($options->kinds)->toBe([])
        ->and($options->cachePath)->toBe('bootstrap/cache/mod-discovery.php')
        ->and($options->onStaleCache)->toBe(CacheMismatchPolicy::Fail)
        ->and(DiscoveryOptions::fromConfig(['on_stale_cache' => 'scan'])->onStaleCache)->toBe(CacheMismatchPolicy::Scan)
        ->and(DiscoveryOptions::fromConfig(['kinds' => ['x' => DiscoveryType::Command]])->kinds)->toBe(['x' => DiscoveryType::Command]);
});

it('rejects invalid config with the offending key', function (array $config, string $message) {
    expect(fn () => DiscoveryOptions::fromConfig($config))->toThrow(InvalidDiscoveryConfig::class, $message);
})->with([
    [['enabled' => 'yes'], '[mod.discovery.enabled]'],
    [['kinds' => 'provider'], '[mod.discovery.kinds]'],
    [['kinds' => ['provider']], 'keys must be kind ids'],
    [['kinds' => ['provider' => 'middleware']], '[mod.discovery.kinds.provider]: expected provider, command, listener or false'],
    [['cache' => ''], '[mod.discovery.cache]'],
    [['on_stale_cache' => 'rebuild'], '[mod.discovery.on_stale_cache]'],
]);

it('rejects kinds the preset cannot discover', function (array $kinds, string $message) {
    expect(fn () => DiscoveryOptions::fromConfig(['kinds' => $kinds])->definitionsFor(Layouts::ordinary()))
        ->toThrow(InvalidDiscoveryConfig::class, $message);
})->with([
    [['handler' => 'command'], '[mod.discovery.kinds.handler]: the active preset does not declare this kind'],
    [['migration' => 'listener'], 'only class kinds can be discovered'],
]);
