<?php

use Inertia\ServiceProvider;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Pages\PageDirectories;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('uses package pages in both finders and the Vite entry', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('vendor/acme/billing/resources/js/pages/Invoices.vue', '<template />');
        Mod::pages('billing', $w->root->path('vendor/acme/billing/src/../resources/js/pages'));
        app()->register(ServiceProvider::class);
        foreach (['inertia.view-finder', 'inertia.testing.view-finder'] as $binding) {
            if (! app()->bound($binding)) {
                continue;
            }
            expect(app($binding)->find('billing::Invoices'))->toEqualPath($w->root->path('vendor/acme/billing/resources/js/pages/Invoices.vue'));
            expect(fn () => app($binding)->find('billing::Missing'))->toThrow(InvalidArgumentException::class);
        }
        expect(Mod::pageEntry('billing::Invoices', 'vue'))->toBe('vendor/acme/billing/resources/js/pages/Invoices.vue');
    });
});

it('rejects duplicate page names and external page directories', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::pages('billing', '/outside/pages');
        expect(fn () => Mod::pages('billing', $w->root->path('pages')))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(PageDirectories::class)->namespaces())->toThrow(InvalidArgumentException::class);
    });
});

it('plans package aliases and sources without writes and applies idempotently', function (string $newline) {
    Workspace::run(null, function (Workspace $w) use ($newline) {
        config()->set('mod.layout', 'modules');
        $w->write('vite.config.ts', str_replace("\n", $newline, "export default defineConfig({\n    resolve: { alias: { '@': '/resources/js' } },\n});\n"));
        $w->write('tsconfig.json', "{\n    \"compilerOptions\": {\n        \"paths\": {\"@/*\": [\"resources/js/*\"]}\n    },\n    \"include\": [\"resources/js/**/*\"],\n    \"exclude\": [\"node_modules\"]\n}");
        $w->write('resources/css/app.css', '@import "tailwindcss";'.$newline);
        $wiring = Mod::wiring()->alias('@acme/billing', 'vendor/acme/billing/resources/js')->source('vendor/acme/billing/resources/js');
        $before = $w->files();
        $plan = $wiring->plan();
        expect($w->files())->toBe($before)->and($plan->toArray()['warnings'])->toBe([]);
        expect($plan->apply())->toBeTrue();
        expect($w->read('vite.config.ts'))->toContain("'@acme/billing':")->and($w->read('tsconfig.json'))->toContain('"exclude": ["node_modules"]')
            ->and($w->read('resources/css/app.css'))->toContain("@source '../../vendor/acme/billing/resources/js';")
            ->and($wiring->plan()->toArray()['files'])->toBe([]);
        if ($newline === "\r\n") {
            expect(str_replace("\r\n", '', $w->read('vite.config.ts')))->not->toContain("\n");
        }
    });
})->with(["\n", "\r\n"]);

it('refuses stale previews and gives precise instructions for custom aliases', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('vite.config.js', "export default defineConfig({\n});");
        $wiring = Mod::wiring()->alias('@billing', 'vendor/acme/billing/resources/js');
        $plan = $wiring->plan();
        $w->write('vite.config.js', 'export default customConfig();');
        expect(fn () => $plan->apply())->toThrow(RuntimeException::class);
        $manual = $wiring->plan();
        expect($manual->toArray()['would_write'])->toBeFalse()->and($manual->toArray()['warnings'][0]['message'])->toContain('@billing', 'vendor/acme/billing/resources/js')
            ->and($manual->apply())->toBeFalse()->and($w->read('vite.config.js'))->toBe('export default customConfig();');
    });
});

it('includes canonical package globs with or without module frontend paths', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        $w->write('vendor/acme/billing/resources/js/pages/Invoices.vue', '<template />');
        $w->write('package.json', '{"dependencies":{"@inertiajs/vue3":"^2"}}');
        $w->write('resources/js/app.js', "createInertiaApp({\n});\n");
        Mod::pages('billing', $w->root->path('vendor/acme/billing/resources/js/pages'));
        $plan = json_decode($w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][0]['identity']['after'])->toContain('billing::', '../../vendor/acme/billing/resources/js/pages/**/*.vue')
            ->and($w->read('resources/js/app.js'))->toBe("createInertiaApp({\n});\n");
    });
})->with(['modules', 'ddd']);
