<?php

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
