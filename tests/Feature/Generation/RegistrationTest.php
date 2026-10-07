<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Commands\RequestCommand;
use Tey\Mod\Generation\InvalidGeneratorSetup;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Which mod:* commands exist is decided once, when Artisan starts, from the
 * preset's kinds and the host's `mod.commands` switch.
 */

/** @return list<string> */
function modCommands(): array
{
    $names = array_values(array_filter(
        array_keys(Artisan::all()),
        static fn (string $name): bool => str_starts_with($name, 'mod:'),
    ));
    sort($names);

    return $names;
}

it('registers one command per preset kind with a command name', function () {
    Workspace::run('modules', function () {
        // routes is a file kind without a generator: not registered.
        expect(modCommands())->toBe([
            'mod:action', 'mod:controller', 'mod:data', 'mod:event', 'mod:factory', 'mod:migration',
            'mod:model', 'mod:policy', 'mod:provider', 'mod:query', 'mod:request', 'mod:seeder',
        ]);
    });
});

it('registers no mod:* command when the host disables them', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        config()->set('mod.commands', false);

        $workspace->artisan('list')->doesntExpectOutputToContain('mod:')->assertSuccessful();

        expect(modCommands())->toBe([]);
    });
});

it('registers no mod:* command when the preset disables them', function () {
    $definition = Layouts::definition('ordinary');
    $definition['commands'] = false;

    Workspace::run($definition, function () {
        expect(modCommands())->toBe([]);
    });
});

it('generates a kind declared only in preset data, with the application stub', function () {
    $definition = Layouts::definition('modules');
    $definition['kinds']['report'] = [
        'shape' => 'class', 'name' => ['suffix' => 'Report'], 'command' => 'mod:report',
        'root' => 'app', 'segments' => ['Modules', '{module}', 'Reports'],
    ];

    Workspace::run($definition, function (Workspace $workspace) {
        $workspace->artisan('mod:report', ['name' => 'Revenue', '--in' => 'Billing'])
            ->expectsOutputToContain('Report [')
            ->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Reports/RevenueReport.php'))
            ->toContain('namespace App\Modules\Billing\Reports;')
            ->toContain('class RevenueReport');

        $workspace->write('stubs/mod.report.stub', "<?php\n\nnamespace {{ namespace }};\n\nfinal class {{ class }} implements \\Stringable\n{\n}\n");

        $workspace->artisan('mod:report', ['name' => 'Churn', '--in' => 'Billing'])->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Reports/ChurnReport.php'))
            ->toContain('final class ChurnReport implements \Stringable');
    });
});

it('refuses an adapter that cannot generate the kind it is mapped to', function () {
    $definition = Layouts::definition('modules');
    $definition['kinds']['routes']['command'] = 'mod:routes';
    config()->set('mod.generators', ['routes' => RequestCommand::class]);

    Workspace::run($definition, function () {
        expect(fn () => Artisan::all())->toThrow(InvalidGeneratorSetup::class, 'cannot generate kind [routes]');
    });
});

it('describes --in with the preset dimensions', function () {
    Workspace::run('vertical-slices', function () {
        $option = Artisan::all()['mod:request']->getDefinition()->getOption('in');

        expect($option->getDescription())->toContain('feature/slice');
    });
});
