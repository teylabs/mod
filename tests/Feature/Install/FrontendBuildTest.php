<?php

use Illuminate\Foundation\Vite;
use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\OwnedAppRoot;

it('builds a mounted alias and Tailwind source with Vite and checks TypeScript', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'frontend-build');
        Mod::layout('frontend-build')->extends('laravel')->mounts('ui', 'App\\UI\\', 'app/UI', fn (Root $root) => $root->frontend(components: 'resources/js', alias: '@/UI'));
        $fixture = dirname(__DIR__, 2).'/Fixtures/frontend-build';
        $w->write('package.json', file_get_contents($fixture.'/package.json'));
        symlink($fixture.'/node_modules', $w->root->path('node_modules'));
        $w->write('vite.config.ts', <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
export default defineConfig({
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }), tailwindcss()],
});
JS);
        $w->write('tsconfig.json', <<<'JSON'
{
    "compilerOptions": {
        "target": "ES2022", "module": "ESNext", "moduleResolution": "bundler", "strict": true, "types": ["node"],
        "paths": {"@/*": ["./resources/js/*"]}
    },
    "include": ["resources/js/**/*"],
    "exclude": ["node_modules"]
}
JSON);
        $w->write('resources/js/app.ts', "import { card } from '@/UI/Card';\nconsole.log(card);\n");
        $w->write('app/UI/resources/js/Card.ts', "export const card: string = 'p-7';\n");
        $w->write('resources/css/app.css', '@import "tailwindcss" source(none);');
        expect(Mod::wiring()->plan()->apply())->toBeTrue();
        foreach (['check', 'build'] as $script) {
            $process = new Process(['npm', 'run', $script], $w->root->path, timeout: 120);
            $process->run();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        }
        $manifest = json_decode($w->read('public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest)->toHaveKeys(['resources/js/app.ts', 'resources/css/app.css'])
            ->and($w->read('public/build/'.$manifest['resources/css/app.css']['file']))->toContain('.p-7');
        expect(Mod::wiring()->plan()->toArray()['files'])->toBe([]);
    });
})->skip(getenv('MOD_FRONTEND_BUILD') !== '1', 'Set MOD_FRONTEND_BUILD=1 after npm ci in tests/Fixtures/frontend-build.');

it('builds static assets and explicit styles with distinct manifest keys and Laravel references', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->assets(files: ['app/Modules/{module}/images/**', 'app/Modules/{module}/fonts/**'], entries: ['app/Modules/{module}/styles/editor.css']);
        $fixture = dirname(__DIR__, 2).'/Fixtures/frontend-build';
        $w->write('package.json', file_get_contents($fixture.'/package.json'));
        symlink($fixture.'/node_modules', $w->root->path('node_modules'));
        $w->write('vite.config.ts', <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.js'], buildDirectory: 'custom-build', refresh: true })],
});
JS);
        $w->write('resources/js/app.js', "import logo from '../../app/Modules/Editor/images/logo.svg';\nimport '../../app/Modules/Inventory/styles/imported.css';\nconsole.log(logo);\n");
        $w->write('app/Modules/Editor/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1" /></svg>');
        $w->write('app/Modules/Inventory/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M1 1h2v2" /></svg>');
        $w->write('app/Modules/Editor/fonts/body.woff2', 'fixture-font');
        $w->write('app/Modules/Editor/styles/editor.css', "@import './nested.css';\n.editor { background: url('../images/logo.svg'); }\n");
        $w->write('app/Modules/Editor/styles/nested.css', "@font-face { font-family: body; src: url('../fonts/body.woff2'); }\n");
        $w->write('app/Modules/Inventory/styles/imported.css', '.imported { color: blue; }');
        $w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful();
        $process = new Process(['npm', 'run', 'build'], $w->root->path, timeout: 120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        $manifest = json_decode($w->read('public/custom-build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $first = 'app/Modules/Editor/images/logo.svg';
        $second = 'app/Modules/Inventory/images/logo.svg';
        $style = 'app/Modules/Editor/styles/editor.css';
        expect($manifest)->toHaveKeys([$first, $second, $style]);
        expect($manifest[$first]['file'])->not->toBe($manifest[$second]['file']);
        $css = $w->read('public/custom-build/'.$manifest[$style]['file']);
        expect($css)->toContain('@font-face', '.editor');
        expect($css)->not->toContain('@import', '../images/logo.svg', '../fonts/body.woff2');
        $vite = app(Vite::class)->useBuildDirectory('custom-build')->useHotFile($w->root->path('missing-hot'));
        expect($vite->asset($first))->toContain('/custom-build/'.$manifest[$first]['file'])
            ->and((string) $vite($style))->toContain($manifest[$style]['file']);
        $w->write('hot', 'http://localhost:5173');
        $vite->useHotFile($w->root->path('hot'));
        expect($vite->asset($first))->toBe('http://localhost:5173/'.$first);
        $w->write('serve-check.mjs', <<<'JS'
import { createServer } from 'vite';
const server = await createServer({ server: { host: '127.0.0.1', port: 0 } });
try {
    await server.listen();
    const address = server.httpServer.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/app/Modules/Editor/images/logo.svg`);
    if (!response.ok || !(await response.text()).includes('<svg')) throw new Error('Missing development asset');
} finally { await server.close(); }
JS);
        // This subprocess deliberately verifies development serving inside CI.
        $process = new Process(['node', 'serve-check.mjs'], $w->root->path, ['LARAVEL_BYPASS_ENV_CHECK' => '1'], timeout: 120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        $w->write('app/Modules/Inventory/images/new.svg', '<svg xmlns="http://www.w3.org/2000/svg" />');
        expect($w->artisan('mod:install', ['stack' => 'assets'])->assertSuccessful()->output)->toContain('Already wired.');
        $process = new Process(['npm', 'run', 'build'], $w->root->path, timeout: 120);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
            ->and(json_decode($w->read('public/custom-build/manifest.json'), true))->toHaveKey('app/Modules/Inventory/images/new.svg');
    });
})->skip(getenv('MOD_FRONTEND_BUILD') !== '1', 'Set MOD_FRONTEND_BUILD=1 after npm ci in tests/Fixtures/frontend-build.');

it('type checks bare and Vue subpath imports from a symlinked package after installation', function () {
    OwnedAppRoot::using(function (OwnedAppRoot $package) {
        app('files')->copyDirectory(__DIR__.'/../../Fixtures/path-package', $package->path);
        Workspace::run(null, function (Workspace $w) use ($package) {
            config()->set('mod.layout', 'laravel');
            $fixture = dirname(__DIR__, 2).'/Fixtures/frontend-build';
            $w->write('package.json', file_get_contents($fixture.'/package.json'));
            symlink($fixture.'/node_modules', $w->root->path('node_modules'));
            mkdir($w->root->path('vendor/acme'), 0755, true);
            symlink($package->path, $w->root->path('vendor/acme/kit'));
            $w->write('vite.config.ts', <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.ts', 'resources/css/app.css'], refresh: true }), vue(), tailwindcss()],
});
JS);
            $w->write('tsconfig.json', <<<'JSON'
{
    "compilerOptions": {
        "target": "ES2022", "module": "ESNext", "moduleResolution": "bundler", "strict": true, "types": ["node"],
        "paths": {}
    },
    "include": ["resources/js/**/*"],
    "exclude": ["node_modules"]
}
JSON);
            $w->write('resources/js/app.ts', "import { kit } from '@acme/kit';\nimport Button from '@acme/kit/Button.vue';\nconsole.log(kit, Button);\n");
            $w->write('resources/css/app.css', '@import "tailwindcss" source(none);');
            Mod::wiring()->alias('@acme/kit', 'vendor/acme/kit/resources/js')->source('vendor/acme/kit/resources/js');
            $w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful();
            $paths = json_decode($w->read('tsconfig.json'), true, flags: JSON_THROW_ON_ERROR)['compilerOptions']['paths'];
            expect($paths)->toBe(['@acme/kit/*' => ['./vendor/acme/kit/resources/js/*'], '@acme/kit' => ['./vendor/acme/kit/resources/js']]);
            foreach ([['node', $fixture.'/node_modules/vue-tsc/bin/vue-tsc.js', '--noEmit'], ['npm', 'run', 'build']] as $command) {
                $process = new Process($command, $w->root->path, timeout: 120);
                $process->run();
                expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            }
            expect($w->artisan('mod:install', ['stack' => 'inertia'])->assertSuccessful()->output)->toContain('Already wired.');
        });
    });
})->skip(getenv('MOD_FRONTEND_BUILD') !== '1', 'Set MOD_FRONTEND_BUILD=1 after npm ci in tests/Fixtures/frontend-build.');
