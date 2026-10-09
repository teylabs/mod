<?php

use Tey\Mod\Discovery\CacheMismatchPolicy;
use Tey\Mod\Discovery\DiscoveredArtifact;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Discovery\Rejection;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('replays cached discovery and lists classes and rejections in verbose output', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.discovery.enabled', true);
        $discovery = new Discovery(app(CompiledLayout::class), new DiscoveryOptions, $w->root->path);
        $inventory = new Inventory([
            new DiscoveredArtifact('listener', DiscoveryType::Listener, 'App\\Modules\\Knowledge\\Listeners\\Sync', 'app/Modules/Knowledge/Listeners/Sync.php'),
        ], [new Rejection('app/Modules/Knowledge/Listeners/Helper.php', RejectionReason::Ineligible, 'not a listener')]);
        $discovery->cache()->write($inventory, $discovery->presetFingerprint(), $discovery->definitionsFingerprint());
        app()->instance(Discovery::class, $discovery);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['discovery']['source'])->toBe('cache')->and($data['discovery']['counts']['listener'])->toBe(1)
            ->and($data['discovery']['entries'])->toBe($inventory->toArray()['entries'])
            ->and($data['discovery']['rejections'])->toBe($inventory->toArray()['rejections']);
        $w->artisan('mod:list')->assertSuccessful()->doesntExpectOutputToContain('Helper.php');
        $w->artisan('mod:list', ['--verbose' => true])->assertSuccessful()->expectsOutputToContain('Sync')->expectsOutputToContain('Helper.php')->expectsOutputToContain('not a listener');
        $w->artisan('mod:list', ['--type' => 'model'])->assertSuccessful()->doesntExpectOutputToContain('Sync')->doesntExpectOutputToContain('Helper.php');
    });
});

it('explains stale discovery caches under both policies', function (string $policy) {
    Workspace::run(null, function (Workspace $w) use ($policy) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.discovery.enabled', true);
        $discovery = new Discovery(app(CompiledLayout::class), new DiscoveryOptions(onStaleCache: CacheMismatchPolicy::from($policy)), $w->root->path);
        $discovery->cache()->write(new Inventory, 'wrong', 'wrong');
        app()->instance(Discovery::class, $discovery);
        $result = $w->artisan('mod:list', ['--json' => true]);
        $data = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        if ($policy === 'scan') {
            $result->assertSuccessful();
            expect($data['discovery']['source'])->toBe('scan')->and($data['discovery']['stale_cache'])->toContain('different layout');
            $w->artisan('mod:list')->assertSuccessful()->expectsOutputToContain('Notices');
        } else {
            $result->assertFailed();
            expect($data['error'])->toContain('different layout');
        }
    });
})->with(['scan', 'fail']);
