<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Support\Stack;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('detects the stack without installing anything', function (?string $package, bool $ts, ?string $inertia, string $casing) {
    Workspace::run(null, function (Workspace $w) use ($package, $ts, $inertia, $casing) {
        if ($package !== null) {
            $w->write('package.json', $package);
        }
        if ($ts) {
            $w->write('tsconfig.json', "{\n// starter kit comment\n\"compilerOptions\": {}\n}");
        }
        $w->write('resources/js/'.$casing.'/.gitkeep', '');
        $before = $w->files();
        $stack = new Stack($w->root->path);
        expect($stack->inertia())->toBe($inertia)
            ->and($stack->typescript())->toBe($ts)
            ->and($stack->pagesPath())->toBe('resources/js/'.$casing)
            ->and($w->files())->toBe($before);
    });
})->with([
    'Vue kit' => ['{"dependencies":{"@inertiajs/vue3":"^2","vue":"^3"}}', true, 'vue', 'pages'],
    'React kit' => ['{"dependencies":{"@inertiajs/react":"^2","react":"^19"}}', true, 'react', 'pages'],
    'Blade only' => ['{"devDependencies":{"vite":"^7"}}', false, null, 'pages'],
    'no package' => [null, false, null, 'pages'],
    'legacy Pages' => ['{"devDependencies":{"@inertiajs/vue3":"^2"}}', false, 'vue', 'Pages'],
    'invalid package' => ['{broken', false, null, 'pages'],
]);

it('resolves the stack service and applies app casing to the active compiled layout', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('package.json', '{"dependencies":{"@inertiajs/react":"^2"}}');
        $w->write('resources/js/Pages/.gitkeep', '');
        expect(app(Stack::class)->inertia())->toBe('react')
            ->and(app(CompiledLayout::class)->frontend()['pages'])->toBe('app/Modules/{module}/resources/js/Pages');
    });
});

it('mirrors the pages directory while preserving a trailing group token in a star path', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->path('app/*/{module}');
        $w->write('resources/js/Pages/.gitkeep', '');
        $layout = app(CompiledLayout::class);
        expect($layout->frontend()['pages'])->toBe('app/resources/js/Pages/{module}')
            ->and($layout->isPlainFilePath('app/resources/js/Pages/Inventory/Widget.vue'))->toBeTrue();
    });
});
