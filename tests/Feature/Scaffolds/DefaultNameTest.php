<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('runs a vendor scaffold bare with its default name and lets an explicit name win', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->named('Acme')->makes('job'), command: 'acme:pages');
        $w->artisan('acme:pages')->assertSuccessful();
        expect($w->exists('app/Jobs/Acme.php'))->toBeTrue();
        $w->artisan('acme:pages', ['name' => 'Billing'])->assertSuccessful();
        expect($w->exists('app/Jobs/Billing.php'))->toBeTrue();
    });
});

it('shows the default in help and reports the resolved name in a dry run', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::scaffold('widgets', fn (Scaffold $s) => $s->named('Acme')->makes('job'));
        $argument = Artisan::all()['mod:widgets']->getDefinition()->getArgument('name');
        expect($argument->isRequired())->toBeFalse()->and($argument->getDefault())->toBe('Acme');
        $plan = json_decode($w->artisan('mod:widgets', ['--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect($plan['name'])->toBe('Acme');
        $plan = json_decode($w->artisan('mod:widgets', ['name' => 'Other', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect($plan['name'])->toBe('Other');
    });
});

it('still requires the name without a default', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::scaffold('plain', fn (Scaffold $s) => $s->makes('job'));
        expect(Artisan::all()['mod:plain']->getDefinition()->getArgument('name')->isRequired())->toBeTrue();
        expect(fn () => $w->artisan('mod:plain'))->toThrow(RuntimeException::class, 'Not enough arguments');
    });
});

it('refuses an empty default name', function () {
    expect(fn () => (new Scaffold)->named('  '))->toThrow(GenerationRefused::class);
});
