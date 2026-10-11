<?php

use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

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
