<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * A mod:* command that another built-in layout has, run in an application
 * whose layout lacks it: it says which layout has it and how to add the file
 * type, instead of Symfony's "Command is not defined".
 */

it('names the layouts that have a mod:* command the active layout lacks', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $result = $workspace->artisan('mod:handler', ['name' => 'Knowledge:Thing']);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('mod:handler is not a command of the modules layout. The slices layout has it.')
            ->and($result->output)->toContain("Mod::layout('modules')->kind('handler', in: '...')")
            ->and($workspace->files())->toBe([]);
    });
});

it('lists every layout that has the command, aliases included', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'laravel');

        expect($workspace->artisan('mod:query', ['name' => 'Overdue'])->output)
            ->toContain('mod:query is not a command of the laravel layout. The features, slices, type-first and modules layouts have it.')
            ->and($workspace->artisan('mod:valueobject', ['name' => 'Money'])->output)
            ->toContain('mod:valueobject is not a command of the laravel layout. The ddd layout has it.');
    });
});

it('keeps those commands out of the list', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        expect($workspace->artisan('list', ['namespace' => 'mod'])->output)
            ->not->toContain('mod:handler')
            ->toContain('mod:dto');
    });
});

it('gives way to a command of the same name from the application or a package', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        Artisan::command('mod:handler {name}', function () {
            $this->info('the application\'s own');
        });

        expect($workspace->artisan('mod:handler', ['name' => 'Thing'])->output)->toContain("the application's own");
    });
});

it('adds nothing when the mod:* commands are turned off', function () {
    Workspace::run(null, function () {
        config()->set('mod.layout', 'modules');
        config()->set('mod.commands', false);

        expect(Artisan::all())->not->toHaveKey('mod:handler');
    });
});
