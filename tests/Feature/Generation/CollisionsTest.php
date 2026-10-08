<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Collisions are diagnosed for the whole plan before anything is written.
 */

it('refuses to overwrite an existing file, and overwrites it with --force', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])
            ->expectsOutputToContain('app/Modules/Billing/Models/Invoice.php already exists.')
            ->doesntExpectOutputToContain('Nothing was written.')
            ->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toBe('<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--force' => true])->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toContain('class Invoice extends Model');
    });
});

it('refuses a class that already exists elsewhere, even with --force', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $fqcn = 'App\Models\CollidingInvoice'.bin2hex(random_bytes(4));
        $basename = class_basename($fqcn);
        $workspace->write("app/Legacy/{$basename}.php", "<?php\n\nnamespace App\\Models;\n\nclass {$basename} {}\n");
        require $workspace->root->path("app/Legacy/{$basename}.php");

        $workspace->artisan('mod:model', ['name' => $basename, '--force' => true])
            ->expectsOutputToContain("The class {$fqcn} already exists.")
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->exists("app/Models/{$basename}.php"))->toBeFalse();
    });
});

it('refuses the whole plan when a related artifact collides', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Database/Factories/InvoiceFactory.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--factory' => true, '--force' => true])
            ->expectsOutputToContain('app/Modules/Billing/Database/Factories/InvoiceFactory.php already exists.')
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        // --force covers the primary only; the model was not written either.
        expect($workspace->files())->toBe(['app/Modules/Billing/Database/Factories/InvoiceFactory.php']);
    });
});

it('refuses related artifacts that collide with each other', function () {
    $definition = Layouts::definition('modules');
    $definition['relations']['update-request']['name'] = ['prefix' => 'Store'];

    Workspace::run($definition, function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nclass Invoice {}\n");

        $workspace->artisan('mod:controller', ['name' => 'Invoice', '--in' => 'Billing', '--model' => 'Invoice', '--requests' => true])
            ->expectsOutputToContain('app/Modules/Billing/Requests/StoreInvoiceRequest.php already exists.')
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->files())->toBe(['app/Modules/Billing/Models/Invoice.php']);
    });
});

it('exits 0 when every file of the plan already exists, as make:* does', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', '<?php // mine');
        $workspace->write('app/Modules/Billing/Database/Factories/InvoiceFactory.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--factory' => true])
            ->expectsOutputToContain('app/Modules/Billing/Models/Invoice.php already exists.')
            ->expectsOutputToContain('app/Modules/Billing/Database/Factories/InvoiceFactory.php already exists.')
            ->doesntExpectOutputToContain('Nothing was written.')
            ->assertSuccessful();
    });
});

it('exits 1 when a file that does not exist yet is not written', function (array $existing, array $options) {
    Workspace::run('modules', function (Workspace $workspace) use ($existing, $options) {
        foreach ($existing as $path) {
            $workspace->write($path, '<?php // mine');
        }

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', ...$options])
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->files())->toBe($existing);
    });
})->with([
    'the factory exists, the model does not' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php'], ['--factory' => true]],
    'both exist, the migration is new' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php', 'app/Modules/Billing/Models/Invoice.php'], ['--factory' => true, '--migration' => true]],
    '--force asks to overwrite the model' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php', 'app/Modules/Billing/Models/Invoice.php'], ['--factory' => true, '--force' => true]],
]);
