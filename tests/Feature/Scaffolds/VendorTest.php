<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

function vendorRecipe(string $provider, string $name = 'pages', string $type = 'job'): void
{
    app(ScaffoldRegistry::class)->register($name, fn (Scaffold $s) => $s->makes($type), source: $provider, vendor: 'acme');
}

/** @return array<string, array<string, mixed>> */
function vendorInventory(Workspace $w): array
{
    return array_column(json_decode($w->artisan('mod:list', ['--json' => true])->output, true)['scaffolds']['items'], null, 'id');
}

it('registers vendor commands and parts with inventory provenance', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->makes('job')->part('form', fn (Part $p) => $p->makes('job')));
        expect(Artisan::all())->toHaveKeys(['mod:acme/pages', 'mod:acme/pages.form']);
        $row = vendorInventory($w)['acme/pages'];
        expect($row)->toMatchArray(['vendor' => 'acme', 'name' => 'pages', 'id' => 'acme/pages', 'command' => 'mod:acme/pages', 'notices' => []]);
        $w->artisan('mod:acme/pages', ['name' => 'Invoice'])->assertSuccessful();
    });
});

it('refuses unprefixed package scaffolds and gives the exact upgrade call', function () {
    Workspace::run(null, function (Workspace $w) {
        app(ScaffoldRegistry::class)->register('pages', fn (Scaffold $s) => $s->makes('job'), source: 'acme/kit');
        $w->artisan('mod:pages', ['name' => 'Invoice'])->assertFailed()->expectsOutputToContain("Mod::vendor('vendor-id')->scaffold('pages', ...)");
        expect(vendorInventory($w)['pages']['notices'][0]['code'])->toBe('vendor_required');
        $plan = json_decode($w->artisan('mod:pages', ['--dry-run' => true, '--json' => true])->assertFailed()->output, true);
        expect($plan['warnings'][0]['code'])->toBe('vendor_required');
    });
});

it('prefers the app override and reports it in plans and inventory', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        vendorRecipe('FirstProvider');
        Mod::scaffold('acme/pages', fn (Scaffold $s) => $s->makes('model'));
        // A pin never replaces the app definition.
        config()->set('mod.scaffolds.prefer', ['acme/pages' => 'FirstProvider']);
        $row = vendorInventory($w)['acme/pages'];
        expect($row['overrides'])->toBe(['FirstProvider'])->and($row['notices'][0]['code'])->toBe('vendor_scaffold_overridden');
        if ($interactive) {
            Examples::testCase()->artisan('mod:acme/pages', ['name' => 'Invoice'])->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        } else {
            $w->artisan('mod:acme/pages', ['name' => 'Invoice'])->assertSuccessful()->expectsOutputToContain("Using your app's acme/pages");
        }
        expect($w->exists('app/Models/Invoice.php'))->toBeTrue();
        $p = json_decode($w->artisan('mod:acme/pages', ['name' => 'Another', '--dry-run' => true, '--json' => true])->output, true);
        expect($p['warnings'][0]['code'])->toBe('vendor_scaffold_overridden')->and($p['would_write'])->toBeTrue();
    });
})->with([false, true]);

it('warns for a registered vendor prefix and allows unrelated app names', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        vendorRecipe('FirstProvider');
        foreach (['acme/crud', 'admin/crud', 'pages'] as $name) {
            Mod::scaffold($name, fn (Scaffold $s) => $s->makes('job'));
        }
        $rows = vendorInventory($w);
        expect($rows['acme/crud']['notices'][0]['code'])->toBe('vendor_prefix_shadowed')
            ->and($rows['admin/crud']['notices'])->toBe([])->and($rows['pages']['notices'])->toBe([]);
        if ($interactive) {
            Examples::testCase()->artisan('mod:acme/crud', ['name' => 'Invoice'])->expectsConfirmation('Run this app scaffold?', 'yes')->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        } else {
            $w->artisan('mod:acme/crud', ['name' => 'Invoice'])->assertSuccessful()->expectsOutputToContain('acme-crud');
        }
        expect($w->exists('app/Jobs/Invoice.php'))->toBeTrue();
    });
})->with([false, true]);

it('resolves conflicts only with a run selection or a provider pin', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        vendorRecipe('FirstProvider');
        vendorRecipe('SecondProvider', type: 'model');
        expect(vendorInventory($w)['acme/pages']['notices'][0]['code'])->toBe('vendor_scaffold_conflict');
        if ($interactive) {
            Examples::testCase()->artisan('mod:acme/pages', ['name' => 'Invoice'])
                ->expectsChoice('Choose a provider for acme/pages (this run only)', 'SecondProvider', ['FirstProvider' => 'FirstProvider', 'SecondProvider' => 'SecondProvider'])
                ->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
            expect($w->exists('app/Models/Invoice.php'))->toBeTrue();
        } else {
            $w->artisan('mod:acme/pages', ['name' => 'Invoice'])->assertFailed()->expectsOutputToContain('FirstProvider')->expectsOutputToContain('SecondProvider')->expectsOutputToContain("'prefer'");
            expect($w->exists('app/Jobs/Invoice.php'))->toBeFalse();
        }
        $p = json_decode($w->artisan('mod:acme/pages', ['name' => 'Another', '--dry-run' => true, '--json' => true])->assertFailed()->output, true);
        expect($p['warnings'][0]['code'])->toBe('vendor_scaffold_conflict')->and($p['would_write'])->toBeFalse();
        config()->set('mod.scaffolds.prefer', ['acme/pages' => 'SecondProvider']);
        // Fresh command resolution uses the pin, without carrying the run selection.
        $w->artisan('mod:acme/pages', ['name' => 'Pinned'])->assertSuccessful();
        expect($w->exists('app/Models/Pinned.php'))->toBeTrue();
    });
})->with([false, true]);

it('honours custom command names and refuses Artisan command collisions', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->asks('label')->makes('job'), command: 'kit:pages');
        Mod::vendor('acme')->scaffold('bad', fn (Scaffold $s) => $s->makes('job'), command: 'mod:list');
        expect(Artisan::all())->toHaveKey('kit:pages');
        expect(array_key_exists('mod:acme/pages', Artisan::all()))->toBeFalse();
        $w->artisan('kit:pages', ['name' => 'Invoice', '--label' => 'Hello'])->assertSuccessful()->expectsOutputToContain('kit:pages will write');
        expect(vendorInventory($w)['acme/pages']['command'])->toBe('kit:pages');
        expect(app(ScaffoldRegistry::class)->problems()['acme/bad'])->toContain('mod:list');
        $data = json_decode($w->artisan('mod:list', ['--json' => true, '--vendor' => 'acme'])->output, true);
        expect(array_column($data['scaffolds']['items'], 'id'))->toContain('acme/pages');
        $w->artisan('mod:list', ['--vendor' => 'acme'])->assertSuccessful()->expectsOutputToContain('kit:pages');
    });
});

it('runs a custom command bare with the recipe default name, and an explicit name wins', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->named('Acme')->makes('job')->part('form', fn (Part $p) => $p->makes('model')), command: 'acme:pages');
        Mod::vendor('acme')->scaffold('reports', fn (Scaffold $s) => $s->named('Report')->makes('job')->makes('model', name: '{name}Total'));
        expect($w->artisan('help', ['command_name' => 'acme:pages'])->output)->toContain('[default: "Acme"]');
        $plan = json_decode($w->artisan('acme:pages', ['--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect($plan['name'])->toBe('Acme')->and(array_column($plan['files'], 'path'))->toBe(['app/Jobs/Acme.php']);
        $w->artisan('acme:pages')->assertSuccessful()->expectsOutputToContain('for Acme.');
        $w->artisan('acme:pages', ['name' => 'Billing'])->assertSuccessful();
        $w->artisan('mod:acme/reports')->assertSuccessful();
        expect($w->exists('app/Jobs/Acme.php'))->toBeTrue()->and($w->exists('app/Jobs/Billing.php'))->toBeTrue()
            ->and($w->exists('app/Jobs/Report.php'))->toBeTrue()->and($w->exists('app/Models/ReportTotal.php'))->toBeTrue();
        // The part's command still takes the root's name and its own value.
        expect(Artisan::all()['acme:pages.form']->getDefinition()->getArgument('name')->isRequired())->toBeTrue();
    });
});

it('still requires the name when a recipe declares no default', function () {
    Workspace::run(null, function (Workspace $w) {
        Mod::vendor('acme')->scaffold('pages', fn (Scaffold $s) => $s->makes('job'), command: 'acme:pages');
        $w->artisan('acme:pages');
    });
})->throws(RuntimeException::class, 'Not enough arguments (missing: "name").');

it('refuses an empty default name and a default name on a part', function () {
    expect(fn () => (new Scaffold)->named(' '))->toThrow(GenerationRefused::class, 'A scaffold default name cannot be empty.')
        ->and(fn () => (new Part)->named('Acme'))->toThrow(GenerationRefused::class, "A part takes its root scaffold's name.");
});

it('keeps vendor ids in includes and layout overrides and diagnoses undeclared parts', function () {
    Workspace::run(null, function (Workspace $w) {
        vendorRecipe('FirstProvider');
        Mod::scaffold('bundle', fn (Scaffold $s) => $s->include('acme/pages'));
        Mod::layout('laravel')->scaffolds('acme/pages', fn (Scaffold $s) => $s->makes('model'));
        Mod::vendor('acme')->scaffold('pages.nope', fn (Part $p) => $p->makes('job'));
        $w->artisan('mod:acme/pages', ['name' => 'Invoice'])->assertSuccessful();
        $w->artisan('mod:bundle', ['name' => 'Included'])->assertSuccessful();
        $w->artisan('mod:acme/pages.nope', ['name' => 'Nope'])->assertFailed()->expectsOutputToContain('part');
        expect($w->exists('app/Models/Invoice.php'))->toBeTrue()->and($w->exists('app/Jobs/Included.php'))->toBeTrue();
    });
});

it('allows declining a shadowed prefix without writing', function () {
    Workspace::run(null, function (Workspace $w) {
        vendorRecipe('FirstProvider');
        Mod::scaffold('acme/crud', fn (Scaffold $s) => $s->makes('job'));
        Examples::testCase()->artisan('mod:acme/crud', ['name' => 'Invoice'])->expectsConfirmation('Run this app scaffold?', 'no')->assertSuccessful();
        expect($w->exists('app/Jobs/Invoice.php'))->toBeFalse();
    });
});

it('offers the vendor command for a missing prefix without generating', function () {
    Workspace::run(null, function (Workspace $w) {
        vendorRecipe('FirstProvider', name: 'notification-centre');
        $application = Artisan::all()['mod:list']->getApplication();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $output = new BufferedOutput;
        $exit = $application->run(new ArrayInput(['command' => 'mod:notification-centre', '--no-interaction' => true]), $output);
        expect($exit)->toBe(1)->and($output->fetch())->toContain('mod:acme/notification-centre');
        expect($w->exists('app/Jobs/Invoice.php'))->toBeFalse();
    });
});

it('renames vendor ids and blocks ambiguous rename selections', function () {
    Workspace::run(null, function (Workspace $w) {
        RenameScenario::setup($w);
        vendorRecipe('FirstProvider', type: 'model');
        $data = RenameScenario::preview($w, ['--scaffold' => 'acme/pages']);
        expect($data['selection']['scaffold'])->toBe('acme/pages')->and($data['moves'])->toHaveCount(1);
        vendorRecipe('SecondProvider', type: 'model');
        $data = RenameScenario::preview($w, ['--scaffold' => 'acme/pages']);
        expect($data['would_write'])->toBeFalse()->and($data['warnings'][0]['code'])->toBe('vendor_scaffold_conflict');
    });
});
