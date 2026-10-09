<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryCache;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('discovers a template listener only with an explicit file type mapping', function (bool $mapped) {
    Workspace::run(null, function (Workspace $workspace) use ($mapped) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/Webhooks/webhook.stub', TemplateScenario::CLASS_STUB);
        $workspace->write('app/Modules/Agents/Webhooks/TemplateListener.php', "<?php\nnamespace App\\Modules\\Agents\\Webhooks;\nclass TemplateListener { public function handle(\\Illuminate\\Auth\\Events\\Login \$event): void {} }\n");
        $options = DiscoveryOptions::fromConfig(['file_types' => $mapped ? ['webhook' => 'listener'] : []]);
        $discovery = new Discovery(app(CompiledLayout::class), $options, $workspace->root->path);
        $loader = new ClassLoader;
        $loader->addClassMap(['App\\Modules\\Agents\\Webhooks\\TemplateListener' => $workspace->root->path('app/Modules/Agents/Webhooks/TemplateListener.php')]);
        $loader->register();
        try {
            expect($discovery->scan()->ofType(DiscoveryType::Listener))->toHaveCount($mapped ? 1 : 0);
        } finally {
            $loader->unregister();
        }
    });
})->with([true, false]);

it('sees a new template in the console and reports the discovery cache stale', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $discovery = new Discovery(app(CompiledLayout::class), DiscoveryOptions::fromConfig([]), $workspace->root->path);
        $discovery->writeCache();
        $workspace->write('stubs/mod/@module/New/new-file.stub', TemplateScenario::CLASS_STUB);
        // A second console process compiles again while retaining its on-disk cache.
        app()->forgetInstance(CompiledLayout::class);
        $next = new Discovery(app(CompiledLayout::class), DiscoveryOptions::fromConfig([]), $workspace->root->path);
        $next->inventory();
        expect($next->staleCacheReason())->toContain('different layout')
            ->and(app(CompiledLayout::class)->hasKind('new-file'))->toBeTrue();
    });
});

it('replays the cached template list on a web request without walking template folders', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $layout = app(CompiledLayout::class);
        $options = DiscoveryOptions::fromConfig([]);
        (new Discovery($layout, $options, $workspace->root->path))->writeCache();
        // A missing tree must not remove cached file types on a web request.
        rename($workspace->root->path('stubs/mod'), $workspace->root->path('stubs/hidden'));
        $cache = new DiscoveryCache($workspace->root->path('bootstrap/cache/mod-discovery.php'));
        $catalog = new TemplateCatalog($workspace->root->path, app(StubRegistry::class), $cache->templates());
        $registry = app(LayoutRegistry::class);
        $replayed = $registry->compile('modules', $catalog);
        expect($replayed->hasKind('tool'))->toBeTrue()
            ->and(PresetFingerprint::of($replayed))->toBe(PresetFingerprint::of($layout));
    });
});
