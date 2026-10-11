<?php

use Illuminate\Support\Facades\Blade;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Views\ViewDirectories;
use Tey\Mod\Views\ViewNamespaceRegistrar;

it('shares component-only mounted paths across the installer and inventory', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'mounted');
        Mod::layout('mounted')->extends('laravel')->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root->frontend(components: 'resources/js', alias: '@/UI'));
        $w->write('app/UI/resources/js/Card.ts', 'export const card = 1;');
        $w->write('vite.config.ts', "export default defineConfig({\n resolve: { alias: { '@': '/resources/js' } },\n});");
        $w->write('resources/css/app.css', '@import "tailwindcss";');
        $plan = Mod::wiring()->plan();
        expect($plan->toArray()['warnings'])->toBe([])->and($plan->apply())->toBeTrue();
        $vite = $w->read('vite.config.ts');
        expect(strpos($vite, "'@/UI'"))->toBeLessThan(strpos($vite, "'@'"))
            ->and($vite)->not->toContain('input:')
            ->and($w->read('resources/css/app.css'))->toContain("@source '../../app/UI/resources/js';");
        $inventory = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($inventory['frontend_owners'][0]['components'])->toBe('app/UI/resources/js')
            ->and($inventory['frontend_owners'][0]['pages'])->toBeNull()
            ->and($inventory['frontend_wiring'][0]['vite_alias'])->toBeTrue();
    });
});

it('resolves explicit mounted pages and Blade views through the declared owner', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'mounted');
        Mod::layout('mounted')->extends('modules')->path('app/Modules/{module}')->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root
            ->frontend(pages: 'screens', pageName: 'UI::{path}', views: 'templates', viewNamespace: 'ui'));
        $w->write('app/UI/screens/Preview.tsx', 'export default () => null;');
        $w->write('app/UI/templates/preview.blade.php', '<h1>Preview</h1>');
        expect(Mod::pageEntry('UI::Preview', 'tsx'))->toBe('app/UI/screens/Preview.tsx');
        $entries = (new ViewDirectories(app(CompiledLayout::class), $w->root->path))->entries();
        expect(array_column($entries, 'namespace'))->toContain('ui');
        ViewNamespaceRegistrar::register(app());
        expect(Blade::render("@include('ui::preview')"))->toBe('<h1>Preview</h1>');
    });
});

it('preserves mounted frontend metadata across inheritance and serialisation', function () {
    $registry = new LayoutRegistry;
    $registry->layout('parent')->path('app')->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(components: 'js', alias: '@/UI'));
    $registry->layout('child')->extends('parent');
    $layout = $registry->compile('child');
    expect($layout->frontendOwners())->toBe($registry->compile('parent')->frontendOwners())
        ->and(unserialize(serialize($layout))->frontendOwners())->toBe($layout->frontendOwners());
});

it('rejects malformed mounted declarations before planning', function (array $arguments) {
    $layout = (new Layout('custom'))->path('app')->mounts('ui', null, 'app/UI', fn (Root $root) => $root->frontend(...$arguments));
    expect(fn () => $layout->compile())->toThrow(InvalidArgumentException::class);
})->with([
    [['pages' => 'screens']],
    [['views' => 'templates']],
    [['components' => '../private', 'alias' => '@/UI']],
    [['components' => '/external', 'alias' => '@/UI']],
]);
