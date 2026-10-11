<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Pest\TestSuite;
use Tey\Mod\Discovery\Console\DiscoveryCacheCommand;
use Tey\Mod\Discovery\Console\DiscoveryClearCommand;
use Tey\Mod\Discovery\DiscoveryCache;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;
use Tey\Mod\Translations\TranslationDirectories;
use Tey\Mod\Translations\TranslationNamespaceRegistrar;

it('loads module translations at boot with application overrides and either discovery setting', function (bool $discovery, bool $resolved) {
    OwnedAppRoot::using(function (OwnedAppRoot $root) use ($discovery, $resolved) {
        foreach (['Inventory', 'AgentTools'] as $group) {
            mkdir($root->path('app/Modules/'.$group.'/lang/en'), 0700, true);
            file_put_contents($root->path('app/Modules/'.$group.'/lang/en/messages.php'), "<?php return ['saved' => 'Module saved', 'other' => 'Module other'];");
        }
        mkdir($root->path('lang/vendor/inventory/en'), 0700, true);
        file_put_contents($root->path('lang/vendor/inventory/en/messages.php'), "<?php return ['saved' => 'App saved'];");
        $case = TestSuite::getInstance()->test;
        if (! $case instanceof TestCase) {
            throw new RuntimeException('Expected the package Testbench case.');
        }
        $case->bootApplicationUsing(function (Application $app) use ($root, $discovery, $resolved) {
            $app->setBasePath($root->path);
            $app->useLangPath($root->path('lang'));
            $app->make('config')->set('mod.layout', 'modules');
            $app->make('config')->set('mod.discovery.enabled', $discovery);
            if ($resolved) {
                $app->booting(fn () => $app->make('translator'));
            }
        });
        expect(__('inventory::messages.saved'))->toBe('App saved')
            ->and(__('inventory::messages.other'))->toBe('Module other')
            ->and(__('agent-tools::messages.saved'))->toBe('Module saved');
    });
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('uses custom nested ddd paths and view namespace naming after opt-in', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        $w->write('app/Modules/Knowledge/Search/lang/en/messages.php', "<?php return ['saved' => 'Saved'];");
        expect(app(TranslationDirectories::class)->entries())->toBe([]);
        app()->forgetScopedInstances();
        Mod::layout('translated-ddd')->extends('ddd')->frontend(lang: 'app/Modules/{domain}/lang');
        config()->set('mod.layout', 'translated-ddd');
        app()->forgetInstance(CompiledLayout::class);
        TranslationNamespaceRegistrar::register(app());
        expect(__('knowledge.search::messages.saved'))->toBe('Saved');
    });
});

it('follows relocated module paths and inherited tokens', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::layout('areas')->extends('modules')->path('src/Areas/{area}');
        config()->set('mod.layout', 'areas');
        $w->write('src/Areas/AgentTools/lang/en/messages.php', "<?php return ['saved' => 'Relocated'];");
        TranslationNamespaceRegistrar::register(app());
        expect(__('agent-tools::messages.saved'))->toBe('Relocated');
    });
});

it('preserves existing namespaces and skips their JSON path with a warning', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/lang/en/messages.php', "<?php return ['saved' => 'Module'];");
        $w->write('app/Modules/Inventory/lang/en.json', '{"Clashing JSON": "Module"}');
        $w->write('original/en/messages.php', "<?php return ['saved' => 'Original'];");
        $translator = new Translator(new FileLoader(app('files'), $w->root->path('lang')), 'en');
        $translator->addNamespace('inventory', $w->root->path('original'));
        app()->instance('translator', $translator);
        $warnings = [];
        app('log')->listen(function ($event) use (&$warnings) {
            $warnings[] = $event->message;
        });
        TranslationNamespaceRegistrar::register(app());
        expect(__('inventory::messages.saved'))->toBe('Original')
            ->and(__('Clashing JSON'))->toBe('Clashing JSON')
            ->and(implode("\n", $warnings))->toContain('inventory', 'already registered');
    });
});

it('loads JSON with Laravel application precedence and locale fallback for PHP files', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/lang/en.json', '{"Saved inventory": "Module", "Module only": "Module only translated"}');
        $w->write('app/Modules/Inventory/lang/en/messages.php', "<?php return ['saved' => 'Fallback'];");
        $w->write('lang/en.json', '{"Saved inventory": "App"}');
        app()->instance('translator', new Translator(new FileLoader(app('files'), $w->root->path('lang')), 'en'));
        TranslationNamespaceRegistrar::register(app());
        expect(__('Saved inventory'))->toBe('App')->and(__('Module only'))->toBe('Module only translated');
        app('translator')->setLocale('fr');
        app('translator')->setFallback('en');
        expect(__('inventory::messages.saved'))->toBe('Fallback');
    });
});

it('probes only template candidates and memoizes within the application scope', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/lang/en/messages.php', '<?php');
        $w->write('app/Modules/Inventory/Models/Deep/Unrelated/File.php', '<?php');
        $w->write('app/Modules/Empty/Models/Deep/Unrelated/File.php', '<?php');
        $files = new class extends Filesystem
        {
            /** @var list<string> */
            public array $listed = [];

            /** @param array<int|string>|int|string $depth
             * @return list<string>
             */
            public function directories($directory, array|string|int $depth = 0)
            {
                $this->listed[] = $directory;

                return parent::directories($directory, $depth);
            }
        };
        app()->instance('files', $files);
        $directories = app(TranslationDirectories::class);
        expect($directories->entries())->toHaveCount(1)->and($directories->entries())->toHaveCount(1)
            ->and(array_map(Path::normalize(...), $files->listed))->toBe([Path::normalize($w->root->path('app/Modules'))]);
        app()->forgetScopedInstances();
        expect(app(TranslationDirectories::class))->not->toBe($directories);
    });
});

it('reports translation namespaces and caches folders without caching their contents', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/lang/en/messages.php', "<?php return ['saved' => 'Before'];");
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['translations'])->toBe([['group' => 'Inventory', 'namespace' => 'inventory', 'path' => 'app/Modules/Inventory/lang']]);
        DiscoveryRegistrar::register(app(), app(CompiledLayout::class));
        app(Kernel::class)->registerCommand(app(DiscoveryCacheCommand::class));
        app(Kernel::class)->registerCommand(app(DiscoveryClearCommand::class));
        $w->artisan('mod:cache')->assertSuccessful();
        $w->write('app/Modules/NewModule/lang/en/messages.php', '<?php return [];');
        $w->write('app/Modules/Inventory/lang/en/messages.php', "<?php return ['saved' => 'After'];");
        app()->forgetScopedInstances();
        expect(app(TranslationDirectories::class)->entries())->toHaveCount(1);
        TranslationNamespaceRegistrar::register(app());
        expect(__('inventory::messages.saved'))->toBe('After');
        $w->artisan('mod:clear')->assertSuccessful();
        app()->forgetScopedInstances();
        expect(app(TranslationDirectories::class)->entries())->toHaveCount(2);
    });
});

it('warns when two group names normalize to the same namespace and keeps the first owner', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/AgentTools/lang/en/messages.php', "<?php return ['saved' => 'First'];");
        $w->write('app/Modules/agent-tools/lang/en/messages.php', "<?php return ['saved' => 'Second'];");
        $warnings = [];
        app('log')->listen(function ($event) use (&$warnings) {
            $warnings[] = $event->message;
        });
        TranslationNamespaceRegistrar::register(app());
        expect(__('agent-tools::messages.saved'))->toBe('First')
            ->and(implode("\n", $warnings))->toContain('agent-tools', 'already registered');
    });
});

it('rejects folder caches after a language declaration changes without walking cached trees', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/lang/en/messages.php', '<?php return [];');
        $layout = app(CompiledLayout::class);
        $cache = new DiscoveryCache($w->root->path('bootstrap/cache/mod-discovery.php'));
        $cache->write(new Inventory, PresetFingerprint::of($layout), '', translations: app(TranslationDirectories::class)->entries());
        $files = new class extends Filesystem
        {
            /**
             * @param  array<int|string>|int|string  $depth
             * @return list<string>
             */
            public function directories($directory, array|string|int $depth = 0)
            {
                throw new RuntimeException('A warm cache must not list directories.');
            }
        };
        expect((new TranslationDirectories($layout, $w->root->path, $files, $cache))->entries())->toHaveCount(1);
        Mod::layout('changed')->extends('modules')->path('app/Modules/{module}')->frontend(lang: '@module/translations');
        $w->write('app/Modules/Inventory/translations/en/messages.php', '<?php return [];');
        $changed = app(LayoutRegistry::class)->layout('changed')->compile();
        expect((new TranslationDirectories($changed, $w->root->path, app('files'), $cache))->entries()[0]['path'])
            ->toBe('app/Modules/Inventory/translations');
        $w->write('bootstrap/cache/mod-discovery.php', '<?php return [');
        expect((new TranslationDirectories($layout, $w->root->path, app('files'), $cache))->entries())->toHaveCount(1);
    });
});

it('registers nothing when the module root or language folders do not exist', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        expect(app(TranslationDirectories::class)->entries())->toBe([]);
        app()->forgetScopedInstances();
        $w->write('app/Modules/Inventory/Models/Widget.php', '<?php');
        expect(app(TranslationDirectories::class)->entries())->toBe([]);
        $w->artisan('mod:list', ['--json' => true])->assertSuccessful();
    });
});
