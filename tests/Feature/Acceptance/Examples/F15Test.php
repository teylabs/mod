<?php

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Install\Kit;

it('F15 previews every edit before one confirmation and wires a Vue app', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->artisan('mod:install', ['stack' => 'inertia'])
            ->expectsOutputToContain('mod:install inertia will change 4 files (Inertia with Vue and TypeScript detected).')
            ->expectsOutput('  resources/js/app.ts ........ resolve: module pages through resolveModulePage()')
            ->expectsOutput('  vite.config.ts ............. the @modules alias, module views in refresh paths')
            ->expectsOutput('  tsconfig.json .............. "@modules/*" path, app/Modules/**/resources/js in include')
            ->expectsOutput('  resources/css/app.css ...... @source "../../app/Modules/**/resources/**/*.{vue,ts,blade.php}"')
            ->expectsConfirmation('Apply these changes?', true)
            ->assertSuccessful();
        Kit::assertAfter($w, 'vue-laravel12');
        expect($w->artisan('mod:install inertia')->assertSuccessful()->normalisedOutput())->toBe("\n  INFO  Already wired.  \n\n");
    });
});

it('F15 applies the same plan with no interaction and reports wiring', function () {
    Workspace::run(null, function (Workspace $w) {
        Kit::setup($w);
        $w->artisan('mod:install inertia')->assertSuccessful();
        Kit::assertAfter($w, 'vue-laravel12');
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['wiring'])->toBe(['inertia' => true, 'vite_alias' => true, 'tailwind' => true]);
    });
});
