<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Relations follow resolved identities: the related artifact is placed by
 * the preset (across roots when the layout says so) and referenced by its
 * resolved class name.
 */

it('generates a model with its factory in ordinary Laravel', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--factory' => true])->assertSuccessful();

        $model = $workspace->read('app/Models/Invoice.php');
        $factory = $workspace->read('database/factories/InvoiceFactory.php');

        // Laravel's convention links this pair unaided: no explicit link is written.
        expect($workspace->files())->toBe(['app/Models/Invoice.php', 'database/factories/InvoiceFactory.php'])
            ->and($model)->toContain('/** @use HasFactory<\Database\Factories\InvoiceFactory> */')
            ->and($model)->not->toContain('newFactory')
            ->and($factory)->toContain('namespace Database\Factories;')
            ->and($factory)->toContain('use App\Models\Invoice;')
            ->and($factory)->toContain('@extends Factory<Invoice>')
            ->and($factory)->not->toContain('protected $model');
    });
});

it('links a module model to its module factory by resolved identity', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--factory' => true])->assertSuccessful();

        expect($workspace->files())->toBe([
            'app/Modules/Billing/Database/Factories/InvoiceFactory.php',
            'app/Modules/Billing/Models/Invoice.php',
        ]);

        // Laravel's convention would look for Database\Factories\Modules\Billing\Models\InvoiceFactory.
        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))
            ->toContain('/** @use HasFactory<\App\Modules\Billing\Database\Factories\InvoiceFactory> */')
            ->toContain('protected static function newFactory(): \App\Modules\Billing\Database\Factories\InvoiceFactory')
            ->toContain('return \App\Modules\Billing\Database\Factories\InvoiceFactory::new();')
            ->and($workspace->read('app/Modules/Billing/Database/Factories/InvoiceFactory.php'))
            ->toContain('namespace App\Modules\Billing\Database\Factories;')
            ->toContain('use App\Modules\Billing\Models\Invoice;')
            ->toContain('class InvoiceFactory extends Factory')
            ->toContain('protected $model = Invoice::class;');
    });
});

it('places the factory relation across a feature root in vertical slices', function () {
    Workspace::run('vertical-slices', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '-f' => true])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Billing/Database/Factories/InvoiceFactory.php', 'app/Billing/Models/Invoice.php'])
            ->and($workspace->read('app/Billing/Database/Factories/InvoiceFactory.php'))
            ->toContain('use App\Billing\Models\Invoice;');
    });
});

it('places a bare factory --model as the model kind of the same placement', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:factory', ['name' => 'InvoiceFactory', '--in' => 'Billing', '--model' => 'Invoice'])->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Database/Factories/InvoiceFactory.php'))
            ->toContain('use App\Modules\Billing\Models\Invoice;');
    });
});

it('resolves a factory model through the declared reference relation', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:factory', ['name' => 'InvoiceFactory'])->assertSuccessful();

        expect($workspace->files())->toBe(['database/factories/InvoiceFactory.php'])
            ->and($workspace->read('database/factories/InvoiceFactory.php'))->toContain('use App\Models\Invoice;');
    });
});

it('generates the store and update requests of a module controller', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nclass Invoice {}\n");

        $workspace->artisan('mod:controller', ['name' => 'InvoiceController', '--in' => 'Billing', '--model' => 'Invoice', '--requests' => true])
            ->assertSuccessful();

        expect($workspace->files())->toBe([
            'app/Modules/Billing/Controllers/InvoiceController.php',
            'app/Modules/Billing/Models/Invoice.php',
            'app/Modules/Billing/Requests/StoreInvoiceRequest.php',
            'app/Modules/Billing/Requests/UpdateInvoiceRequest.php',
        ]);

        expect($workspace->read('app/Modules/Billing/Controllers/InvoiceController.php'))
            ->toContain('namespace App\Modules\Billing\Controllers;')
            ->toContain('use App\Modules\Billing\Models\Invoice;')
            ->toContain('use App\Modules\Billing\Requests\StoreInvoiceRequest;')
            ->toContain('use App\Modules\Billing\Requests\UpdateInvoiceRequest;')
            ->toContain('public function store(StoreInvoiceRequest $request)')
            ->toContain('public function update(UpdateInvoiceRequest $request, Invoice $invoice)')
            ->and($workspace->read('app/Modules/Billing/Requests/StoreInvoiceRequest.php'))
            ->toContain('namespace App\Modules\Billing\Requests;');
    });
});

it('offers to generate a missing controller model through mod:model', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:controller', ['name' => 'InvoiceController', '--in' => 'Billing', '--model' => 'Invoice'])
            ->assertSuccessful();

        expect($workspace->files())->toBe([
            'app/Modules/Billing/Controllers/InvoiceController.php',
            'app/Modules/Billing/Models/Invoice.php',
        ]);
    });
});

it('does not generate a reference relation', function () {
    $definition = Layouts::definition('ordinary');
    $definition['relations']['factory']['policy'] = 'reference';

    Workspace::run($definition, function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--factory' => true])
            ->expectsOutputToContain('Related factory [Database\Factories\InvoiceFactory] is a reference; not generated.')
            ->assertSuccessful();

        expect($workspace->files())->toBe(['app/Models/Invoice.php'])
            ->and($workspace->read('app/Models/Invoice.php'))->toContain('HasFactory<\Database\Factories\InvoiceFactory>');
    });
});

it('refuses a companion option without a declared relation, writing nothing', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--controller' => true])
            ->expectsOutputToContain('The preset declares no relation from [model] to [controller].')
            ->assertFailed();

        expect($workspace->files())->toBe([]);
    });
});

it('places a bare policy --model and listener --event', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:policy', ['name' => 'InvoicePolicy', '--model' => 'Invoice'])->assertSuccessful();
        $workspace->artisan('mod:listener', ['name' => 'SendReceipt', '--event' => 'InvoicePaid'])->assertSuccessful();
        $workspace->artisan('mod:listener', ['name' => 'AuditLogin', '--event' => 'Illuminate\Auth\Events\Login'])->assertSuccessful();

        expect($workspace->read('app/Policies/InvoicePolicy.php'))->toContain('use App\Models\Invoice;')
            ->and($workspace->read('app/Listeners/SendReceipt.php'))->toContain('use App\Events\InvoicePaid;')
            ->and($workspace->read('app/Listeners/AuditLogin.php'))->toContain('use Illuminate\Auth\Events\Login;');
    });
});

it('generates every model companion through the default preset relations', function () {
    Workspace::run(config('mod.preset'), function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--all' => true])->assertSuccessful();

        $migration = $workspace->migration('database/migrations', 'create_invoices_table');

        expect($workspace->files())->toBe([
            'app/Http/Controllers/InvoiceController.php',
            'app/Http/Requests/StoreInvoiceRequest.php',
            'app/Http/Requests/UpdateInvoiceRequest.php',
            'app/Models/Invoice.php',
            'app/Policies/InvoicePolicy.php',
            'database/factories/InvoiceFactory.php',
            $migration,
            'database/seeders/InvoiceSeeder.php',
        ]);

        expect($workspace->read('app/Http/Controllers/InvoiceController.php'))
            ->toContain('use App\Http\Requests\StoreInvoiceRequest;')
            ->toContain('use App\Models\Invoice;')
            ->and($workspace->read('app/Policies/InvoicePolicy.php'))->toContain('use App\Models\Invoice;')
            ->and($workspace->read($migration))->toContain("Schema::create('invoices'");
    });
});
