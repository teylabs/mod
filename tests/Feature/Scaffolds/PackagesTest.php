<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Commands\BasesCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('attributes a provider registration to its Composer package through the public facade', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $class = 'ScaffoldPackage'.bin2hex(random_bytes(4));
        $w->write('vendor/acme/kit/composer.json', '{"name":"acme/kit"}');
        $w->write('vendor/acme/kit/src/Provider.php', '<?php class '.$class.' extends \\Illuminate\\Support\\ServiceProvider { public function boot(): void { \\Tey\\Mod\\Facades\\Mod::scaffolds(["package-set" => fn (\\Tey\\Mod\\Scaffolds\\Scaffold $s) => $s->makes("job")]); } }');
        require $w->root->path('vendor/acme/kit/src/Provider.php');
        $provider = new $class(app());
        $provider->boot();
        $w->artisan('mod:package-set', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(app(ScaffoldRegistry::class)->sources()['package-set'])->toBe('acme/kit');
    });
});

it('allows a layout to resolve two ambiguous package recipes', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(ScaffoldRegistry::class);
        foreach (['acme/kit', 'beta/kit'] as $source) {
            $registry->register('crud', fn (Scaffold $s) => $s->makes('job'), $source);
        }
        Mod::layout('modules')->scaffolds('crud', fn (Scaffold $s) => $s->makes('model'));
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($registry->sources()['crud'])->toBe('layout')->and($registry->problems())->toBe([])
            ->and($w->exists('app/Modules/Knowledge/Models/Document.php'))->toBeTrue();
    });
});

it('warns and refuses only the ambiguous package scaffold', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = app(ScaffoldRegistry::class);
        foreach (['acme/inertia-kit', 'beta/admin-kit'] as $source) {
            $registry->register('crud', fn (Scaffold $s) => $s->makes('model'), $source);
        }
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($result->normalisedOutput())->toBe("\n   WARN  Scaffold [crud] is registered by acme/inertia-kit and by beta/admin-kit, so mod:crud is disabled. Define crud in your app to use your own.  \n\n   ERROR  Command \"mod:crud\" is not defined.  \n\n")
            ->and($w->files())->toBe([]);
    });
});

it('disables bad includes and command-name clashes without replacing the real command', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('bases', fn (Scaffold $s) => $s->makes('model'));
        Mod::scaffold('crud', fn (Scaffold $s) => $s->include('missing'));
        $commands = Artisan::all();
        expect($commands['mod:bases'])->toBeInstanceOf(BasesCommand::class)
            ->and(app(ScaffoldRegistry::class)->problems())->toHaveKeys(['bases', 'crud']);
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
    });
});

it('isolates a missing include in a layout override', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->scaffolds('bad', fn (Scaffold $s) => $s->include('missing'));
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(app(ScaffoldRegistry::class)->problems())->toHaveKey('bad');
    });
});
