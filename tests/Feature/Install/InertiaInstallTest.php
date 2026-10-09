<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Install\Kit;
use Tey\Mod\Tests\Support\JsonSchema;

it('matches pinned kit files exactly and is idempotent', function (string $kit) {
    Workspace::run(null, function (Workspace $w) use ($kit) {
        Kit::setup($w, $kit);
        $w->artisan('mod:install inertia')->assertSuccessful();
        Kit::assertAfter($w, $kit);
        $w->artisan('mod:install inertia')->assertSuccessful()->expectsOutputToContain('Already wired.');
        Kit::assertAfter($w, $kit);
    });
})->with(['vue-laravel12', 'react-laravel12', 'vue-current', 'react-current']);

it('lists machine-readable before and after edits without writing or prompting', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $result = $w->artisan('mod:install inertia', ['--dry-run' => true, '--json' => true])->assertSuccessful();
        $plan = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(JsonSchema::errors($plan, $schema))->toBe([])
            ->and($plan['would_write'])->toBeTrue()
            ->and(array_column($plan['files'], 'path'))->toBe(['resources/js/app.ts', 'vite.config.ts', 'tsconfig.json', 'resources/css/app.css']);
        foreach ($plan['files'] as $file) {
            expect(str_replace("\r\n", "\n", $w->read($file['path'])))->toBe($file['identity']['before']);
            expect($file['identity']['after'])->toBe(Kit::files('vue-laravel12', 'after')[$file['path']]);
        }
    });
});

it('leaves all files alone when confirmation is declined', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('mod:install', ['stack' => 'inertia'])->expectsConfirmation('Apply these changes?', false)->assertSuccessful();
        foreach (Kit::files('vue-laravel12', 'before') as $path => $contents) {
            expect(str_replace("\r\n", "\n", $w->read($path)))->toBe($contents);
        }
    });
});

it('refuses to rewrite a custom resolver and prints manual lines before any writes', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w, 'vue-current');
        $custom = str_replace('createInertiaApp({', "createInertiaApp({\n    resolve: (name) => customPages[name],", $w->read('resources/js/app.ts'));
        $w->write('resources/js/app.ts', $custom);
        $w->artisan('mod:install inertia')->assertSuccessful()->expectsOutputToContain('custom resolve')->expectsOutputToContain('resolveModulePage')->expectsOutputToContain('@modules');
        expect($w->read('resources/js/app.ts'))->toBe($custom)
            ->and($w->read('vite.config.ts'))->toBe(Kit::files('vue-current', 'before')['vite.config.ts']);
        $plan = json_decode($w->artisan('mod:install inertia', ['--dry-run' => true, '--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['would_write'])->toBeFalse()->and($plan['warnings'])->not->toBeEmpty();
    });
});

it('needs no install for Blade apps', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->artisan('mod:install inertia')->assertSuccessful()->expectsOutputToContain('Blade apps need no Inertia install.');
        expect($w->files())->toBe([]);
    });
});

it('uses the mirrored P3 pages without editing the app entry', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $before = $w->read('resources/js/app.ts');
        $w->artisan('mod:install inertia')->assertSuccessful();
        expect($w->read('resources/js/app.ts'))->toBe($before);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['wiring']['inertia'])->toBeTrue();
    });
});

it('pins wiring booleans from the files rather than stored state', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $registry = new InventorySectionRegistry;
        $before = $registry->read(app());
        expect($before['wiring'])->toBe(['inertia' => false, 'vite_alias' => false, 'tailwind' => false]);
        $w->artisan('mod:install inertia')->assertSuccessful();
        $data = $registry->read(app());
        expect(JsonSchema::errors($data, $registry->schema()))->toBe([]);
        $data['wiring']['inertia'] = 'yes';
        expect(JsonSchema::errors($data, $registry->schema()))->not->toBe([]);
        $w->write('resources/css/app.css', "@import 'tailwindcss';\n");
        expect($registry->read(app())['wiring']['tailwind'])->toBeFalse();
    });
});

it('preserves CRLF and supports JavaScript entries and Tailwind v3', function (string $stack, string $extension) {
    Workspace::run(null, function (Workspace $w) use ($stack, $extension) {
        config()->set('mod.layout', 'modules');
        $w->write('package.json', json_encode(['dependencies' => ['@inertiajs/'.($stack === 'vue' ? 'vue3' : 'react') => '^2.0', 'tailwindcss' => '^3.0']], JSON_THROW_ON_ERROR));
        $w->write('resources/js/app.'.$extension, "import { createInertiaApp } from '@inertiajs/".($stack === 'vue' ? 'vue3' : 'react')."';\r\ncreateInertiaApp({\r\n});\r\n");
        $w->write('resources/js/Pages/.gitkeep', '');
        $w->write('vite.config.js', "import { defineConfig } from 'vite';\r\nexport default defineConfig({\r\n    plugins: [laravel({refresh: true})],\r\n});\r\n");
        $w->write('tailwind.config.js', "export default {\r\n    content: ['./resources/**/*.blade.php'],\r\n};\r\n");
        $w->artisan('mod:install inertia')->assertSuccessful();
        expect($w->read('resources/js/app.'.$extension))->toContain('../../app/Modules/*/resources/js/Pages/**/*.'.($stack === 'vue' ? 'vue' : 'jsx'))
            ->and($w->read('tailwind.config.js'))->toContain('app/Modules/**/resources/**/*.{'.($stack === 'vue' ? 'vue' : 'jsx').',js,blade.php}')
            ->and(str_replace("\r\n", '', $w->read('vite.config.js')))->not->toContain("\n");
        $w->artisan('mod:install inertia')->assertSuccessful()->expectsOutputToContain('Already wired.');
    });
})->with([['vue', 'js'], ['react', 'jsx']]);

it('uses custom layout roots in every generated path', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->path('src/{module}')->frontend(pages: '@module/ui/pages', components: '@module/ui/components', css: '@module/ui/css', views: '@module/ui/views');
        $w->artisan('mod:install inertia')->assertSuccessful();
        expect($w->read('resources/js/app.ts'))->toContain('../../src/*/ui/pages/**/*.vue')
            ->and($w->read('vite.config.ts'))->toContain("new URL('./src', import.meta.url)")->toContain('src/**/ui/views/**')
            ->and($w->read('tsconfig.json'))->toContain('"./src/*"')->toContain('src/**/ui/**/*');
    });
});
