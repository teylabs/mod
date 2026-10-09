<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Commands\InstallCommand;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Install\Kit;
use Tey\Mod\Tests\Support\JsonSchema;

it('matches pinned kit files exactly and is idempotent', function (string $kit) {
    Workspace::run(null, function (Workspace $w) use ($kit) {
        Kit::setup($w, $kit);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        Kit::assertAfter($w, $kit);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Already wired.');
        Kit::assertAfter($w, $kit);
    });
})->with(['vue-laravel12', 'react-laravel12', 'vue-current', 'react-current']);

it('lists machine-readable before and after edits without writing or prompting', function (string $newline) {
    Workspace::run(null, function (Workspace $w) use ($newline) {
        Kit::setup($w);
        foreach (Kit::files('vue-laravel12', 'before') as $path => $contents) {
            $w->write($path, str_replace("\n", $newline, $contents));
        }
        $result = $w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true])->assertSuccessful();
        $plan = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(JsonSchema::errors($plan, $schema))->toBe([])
            ->and($plan['would_write'])->toBeTrue()
            ->and(array_column($plan['files'], 'path'))->toBe(['resources/js/app.ts', 'vite.config.ts', 'tsconfig.json', 'resources/css/app.css']);
        foreach ($plan['files'] as $file) {
            expect($w->read($file['path']))->toEqualText($file['identity']['before']);
            expect($file['identity']['after'])->toEqualText(Kit::files('vue-laravel12', 'after')[$file['path']]);
            // Preview must preserve the actual source bytes and write nothing.
            expect($w->read($file['path']))->toBe($file['identity']['before']);
        }
    });
})->with(["\n", "\r\n"]);

it('leaves all files alone when confirmation is declined', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        TemplateScenario::testCase()->artisan('mod:install', ['stack' => 'inertia'])->expectsConfirmation('Apply these changes?', 'no')->assertSuccessful();
        foreach (Kit::files('vue-laravel12', 'before') as $path => $contents) {
            expect($w->read($path))->toEqualText($contents);
        }
    });
});

it('refuses to rewrite a custom resolver and prints manual lines before any writes', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w, 'vue-current');
        $custom = str_replace('createInertiaApp({', "createInertiaApp({\n    resolve: (name) => customPages[name],", $w->read('resources/js/app.ts'));
        $w->write('resources/js/app.ts', $custom);
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('custom resolve')->expectsOutputToContain('resolveModulePage')->expectsOutputToContain('@modules');
        expect($w->read('resources/js/app.ts'))->toBe($custom)
            ->and($w->read('vite.config.ts'))->toEqualText(Kit::files('vue-current', 'before')['vite.config.ts']);
        $plan = json_decode($w->artisan('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true])->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['would_write'])->toBeFalse()->and($plan['warnings'][0]['file'])->toBe('resources/js/app.ts');
    });
});

it('needs no install for Blade apps', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Blade apps need no Inertia install.');
        expect($w->files())->toBe([]);
    });
});

it('uses the mirrored P3 pages without editing the app entry', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $before = $w->read('resources/js/app.ts');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
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
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
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
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('resources/js/app.'.$extension))->toContain('../../app/Modules/*/resources/js/Pages/**/*.'.($stack === 'vue' ? 'vue' : 'jsx'))
            ->and($w->read('tailwind.config.js'))->toContain('app/Modules/**/resources/**/*.{'.($stack === 'vue' ? 'vue' : 'jsx').',js,blade.php}')
            ->and(str_replace("\r\n", '', $w->read('vite.config.js')))->not->toContain("\n");
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('Already wired.');
    });
})->with([['vue', 'js'], ['react', 'jsx']]);

it('uses custom layout roots in every generated path', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->path('src/{module}')->frontend(pages: '@module/ui/pages', components: '@module/ui/components', css: '@module/ui/css', views: '@module/ui/views');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('resources/js/app.ts'))->toContain('../../src/*/ui/pages/**/*.vue')
            ->and($w->read('vite.config.ts'))->toContain("new URL('./src', import.meta.url)")->toContain('src/**/ui/views/**')
            ->and($w->read('tsconfig.json'))->toContain('"./src/*"')->toContain('src/**/ui/**/*');
    });
});

it('preserves existing Vite aliases and refresh paths', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $w->write('vite.config.ts', "import { defineConfig } from 'vite';\nexport default defineConfig({\n    resolve: {alias: {'@': '/resources/js'}},\n    plugins: [laravel({refresh: ['custom/**']})],\n});\n");
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('vite.config.ts'))->toContain("'@': '/resources/js'")->toContain("'custom/**'")->toContain("'@modules'");
    });
});

it('refuses an unknown config before writing any file', function (string $file, string $contents) {
    Workspace::run(null, function (Workspace $w) use ($file, $contents) {
        Kit::setup($w);
        $w->write($file, $contents);
        $before = $w->read('resources/js/app.ts');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->expectsOutputToContain('manually');
        expect($w->read($file))->toBe($contents)->and($w->read('resources/js/app.ts'))->toBe($before);
    });
})->with([['vite.config.ts', 'export default customVite;'], ['tsconfig.json', '{"compilerOptions": {"paths": customPaths}}']]);

it('reports absent imports and aliases as unwired even when comments mention them', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $w->write('resources/js/app.ts', "// resolveModulePage(name, import.meta.glob('../../app/Modules/*/resources/js/pages/**/*.vue'))\n".$w->read('resources/js/app.ts'));
        $data = (new InventorySectionRegistry)->read(app());
        expect($data['wiring']['inertia'])->toBeFalse();
    });
});

it('normalizes nonstandard page folders for the shared resolver', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->frontend(pages: '@module/ui/screens');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('resources/js/app.ts'))->toContain('Object.fromEntries')->toContain('../../app/Modules/*/ui/screens/**/*.vue')->toContain('$1::');
    });
});

it('keeps mirrored pages while scanning the module views and CSS', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(LayoutRegistry::class)->layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('resources/css/app.css'))->toContain('../../app/Modules/**/resources/**/*.{vue,ts,blade.php}');
    });
});

it('takes the DDD frontend alias from its frontend group rather than a class root', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        config()->set('mod.layout', 'ddd');
        $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
        expect($w->read('vite.config.ts'))->toContain("new URL('./app/Modules', import.meta.url)");
    });
});

it('detects the actual alias target and active Tailwind source', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $w->write('vite.config.ts', "export default { resolve: { alias: { '@modules': './wrong', '@elsewhere': './app/Modules' } } };\n");
        $w->write('resources/css/app.css', "/* @source '../../app/Modules/**/resources/**/*.{vue,ts,blade.php}'; */\n@import 'tailwindcss';\n");
        $data = (new InventorySectionRegistry)->read(app());
        expect($data['wiring']['vite_alias'])->toBeFalse()->and($data['wiring']['tailwind'])->toBeFalse();
    });
});

it('names the non-interactive flag when there is no terminal and no answer', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app()->bind(InstallCommand::class, fn () => new class extends InstallCommand
        {
            protected function terminalAvailable(): bool
            {
                return false;
            }
        });
        TemplateScenario::testCase()->artisan('mod:install', ['stack' => 'inertia'])
            ->expectsOutputToContain('mod:install inertia needs confirmation. Pass --no-interaction to apply these changes.')
            ->assertExitCode(1);
        expect($w->read('resources/js/app.ts'))->toEqualText(Kit::files('vue-laravel12', 'before')['resources/js/app.ts']);
    });
});
