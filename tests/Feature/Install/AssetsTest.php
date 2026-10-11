<?php

use Tey\Mod\Assets\AssetInventory;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Install\AssetEdits;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('plans assets without Inertia and synchronises optional executable entries', function (string $newline) {
    Workspace::run(null, function (Workspace $w) use ($newline) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->assets(files: ['app/Modules/{module}/images/**'], entries: ['app/Modules/{module}/styles/editor.css']);
        $w->write('app/Modules/Editor/images/logo.svg', '<svg />');
        $w->write('app/Modules/Editor/styles/editor.css', 'body { color: red; }');
        $w->write('package.json', '{"devDependencies":{"laravel-vite-plugin":"^3.0.0"}}');
        $w->write('vite.config.js', str_replace("\n", $newline, "import laravel from 'laravel-vite-plugin';\nexport default defineConfig({\n plugins: [laravel({ input: ['resources/js/app.js'], refresh: true, assets: ['resources/images/**'] })],\n});"));
        $before = $w->files();
        $preview = json_decode($w->artisan('mod:install', ['stack' => 'assets', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['warnings'])->toBe([])->and($preview['would_write'])->toBeTrue()->and($w->files())->toBe($before);
        $w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful();
        $vite = $w->read('vite.config.js');
        expect($vite)->toContain("'resources/js/app.js'", "'resources/images/**'", '...modAssets.entries', '...modAssets.files', 'refresh: true');
        $generated = json_decode($w->read('resources/mod-assets.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($generated['entries'])->toBe(['app/Modules/Editor/styles/editor.css'])->and($generated['files'])->toBe(['app/Modules/*/images/**']);
        $w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful()->expectsOutputToContain('Already wired.');
        unlink($w->root->path('app/Modules/Editor/styles/editor.css'));
        $w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful();
        expect(json_decode($w->read('resources/mod-assets.json'), true)['entries'])->toBe([])->and($w->read('vite.config.js'))->toBe($vite);
        if ($newline === "\r\n") {
            expect(str_replace("\r\n", '', $vite))->not->toContain("\n");
        }
    });
})->with(["\n", "\r\n"]);

it('keeps root assets relative and reports absent optional entries', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'assets');
        Mod::layout('assets')->extends('laravel')->mounts('ui', null, 'app/UI', fn (Root $root) => $root->assets(files: ['images/**'], entries: ['css/theme.css']));
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['assets']['owners'][0]['files'])->toBe(['app/UI/images/**'])
            ->and($data['assets']['owners'][0]['entries'][0])->toBe(['declared' => 'app/UI/css/theme.css', 'path' => 'app/UI/css/theme.css', 'exists' => false]);
    });
});

it('refuses malformed or escaping assets before any application writes', function (array $arguments) {
    Workspace::run(null, function (Workspace $w) use ($arguments) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->assets(...$arguments);
        $before = $w->files();
        expect(fn () => app(CompiledLayout::class))->toThrow(InvalidArgumentException::class)->and($w->files())->toBe($before);
    });
})->with([
    [['files' => ['../secrets/**']]],
    [['files' => ['/external/**']]],
    [['files' => ['images/{broken/**']]],
    [['entries' => ['app/**/editor.css']]],
    [['files' => ['app/editor.css']]],
]);

it('leaves custom Vite shapes and older plugins untouched with precise instructions', function (string $version) {
    Workspace::run(null, function (Workspace $w) use ($version) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->assets(files: ['app/Modules/{module}/images/**']);
        $w->write('package.json', json_encode(['devDependencies' => ['laravel-vite-plugin' => $version]], JSON_THROW_ON_ERROR));
        $w->write('vite.config.js', 'export default customConfig();');
        $w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful()->expectsOutputToContain($version === '^3' ? 'Could not confirm' : 'requires laravel-vite-plugin 3');
        expect($w->read('vite.config.js'))->toBe('export default customConfig();')->and($w->exists('resources/mod-assets.json'))->toBeFalse();
    });
})->with(['^3', '^2']);

it('rejects an escaping asset symlink and preserves application-owned generated paths', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->assets(files: ['app/Modules/{module}/images/**']);
        $w->write('resources/mod-assets.json', '{"application":true}');
        $plan = (new AssetEdits($w->root->path, app(CompiledLayout::class)))->plan();
        expect($plan->wouldWrite)->toBeFalse()->and($w->read('resources/mod-assets.json'))->toBe('{"application":true}');
        if (PHP_OS_FAMILY !== 'Windows') {
            $w->write('app/Modules/Editor/images/.gitkeep', '');
            symlink(sys_get_temp_dir(), $w->root->path('app/Modules/Editor/images/external'));
            expect(fn () => (new AssetInventory(app(CompiledLayout::class), $w->root->path))->build())->toThrow(InvalidArgumentException::class);
        }
    });
});
