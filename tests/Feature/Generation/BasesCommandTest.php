<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * mod:bases: writes the generated bases the layout's kinds extend that are
 * missing, never overwrites one, and writes nothing on a second run.
 */

it('writes the missing bases with mod:bases, once, and never overwrites one', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        [$bases, $basesNamespace] = isolatedBasesPath();
        starterPackages();

        $first = $workspace->artisan('mod:bases')->assertSuccessful();

        expect($first->output)->toContain("Created base class {$basesNamespace}\\Data\\DataTransferObject [{$bases}/Data/DataTransferObject.php].")
            ->and($first->output)->toContain("Created base class {$basesNamespace}\\ViewModels\\ViewModel [{$bases}/ViewModels/ViewModel.php].")
            ->and($workspace->files())->toBe(["{$bases}/Data/DataTransferObject.php", "{$bases}/ViewModels/ViewModel.php"]);

        $workspace->write("{$bases}/Data/DataTransferObject.php", "<?php // edited\n");
        $second = $workspace->artisan('mod:bases')->assertSuccessful();

        expect($second->output)->toContain('Every base class already exists.')
            ->and($second->output)->not->toContain('Created base class')
            ->and($workspace->read("{$bases}/Data/DataTransferObject.php"))->toBe("<?php // edited\n");
    });
});

it('writes only the bases mod:bases still needs, from a published base stub', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        config()->set('mod.bases.view-model', 'App\\UI\\ViewModel');
        [$bases] = isolatedBasesPath();
        starterPackages();
        $workspace->write('stubs/mod.base.data-transfer-object.stub', "<?php\n\nnamespace {{ namespace }};\n\nabstract class {{ class }}\n{\n    // the application's base\n}\n");

        $workspace->artisan('mod:bases')->assertSuccessful();

        expect($workspace->files())->toBe(["{$bases}/Data/DataTransferObject.php", 'stubs/mod.base.data-transfer-object.stub'])
            ->and($workspace->read("{$bases}/Data/DataTransferObject.php"))->toContain("// the application's base");
    });
});

it('says when mod:bases has nothing to write', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'laravel');

        $workspace->artisan('mod:bases')
            ->expectsOutputToContain('No file type in this layout extends a generated base class.')
            ->assertSuccessful();

        expect($workspace->files())->toBe([])
            ->and(Artisan::all())->toHaveKey('mod:bases')
            ->and(Artisan::all()['mod:bases']->getDescription())->toBe('Create every missing base class the layout can use, whether or not a class extends it yet')
            ->and(Artisan::all()['mod:bases']->getHelp())->toContain('every base class the layout\'s file types can extend');
    });
});
