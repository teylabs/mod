<?php

use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidDiscoveryCache;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

it('reads only the host cache and leaves memoised app state alone', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = $fx->layout('ordinary');
    $fx->write('app/Providers/FirstServiceProvider.php', Sources::provider('App\\Providers', 'FirstServiceProvider', 'host.first'));
    $discovery = new Discovery(layout: $layout, options: DiscoveryOptions::fromConfig([]), basePath: $fx->path());
    expect(fn () => $discovery->readCache())->toThrow(InvalidDiscoveryCache::class, 'does not exist');
    expect(is_file($fx->path('bootstrap/cache/mod-discovery.php')))->toBeFalse();
    $written = $discovery->cacheInventory();
    $fx->write('app/Providers/LateServiceProvider.php', Sources::provider('App\\Providers', 'LateServiceProvider', 'host.late'));
    expect($discovery->readCache()->ofType(DiscoveryType::Provider))->toHaveCount(1)
        ->and($discovery->scan()->ofType(DiscoveryType::Provider))->toHaveCount(2)
        ->and($discovery->source())->toBeNull()
        ->and($written->ofType(DiscoveryType::Provider)[0]->fileType)->toBe('provider')
        ->and(app()->bound('host.first'))->toBeFalse();
}));

it('refuses foreign stale and malformed host caches without fallback scans', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $layout = $fx->layout('ordinary');
    $scans = 0;
    $options = DiscoveryOptions::fromConfig([])->withCandidates(function () use (&$scans): array {
        $scans++;

        return [];
    });
    $discovery = new Discovery($layout, $options, $fx->path());
    $discovery->cacheInventory();
    $before = $scans;
    $file = $fx->path('bootstrap/cache/mod-discovery.php');
    $contents = file_get_contents($file);
    foreach (['preset', 'definitions'] as $key) {
        $payload = require $file;
        $payload[$key] = 'foreign';
        file_put_contents($file, '<?php return '.var_export($payload, true).';');
        expect(fn () => $discovery->readCache())->toThrow(InvalidDiscoveryCache::class);
        file_put_contents($file, $contents);
    }
    file_put_contents($file, '<?php return [');
    expect(fn () => $discovery->readCache())->toThrow(InvalidDiscoveryCache::class)
        ->and($scans)->toBe($before);
}));
