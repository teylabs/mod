<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

/*
 * Every kind generates through its mod:* command at exactly the path,
 * namespace and class name the preset resolves.
 */

dataset('ordinary kinds', [
    'model' => ['mod:model', 'Invoice', 'app/Models/Invoice.php', 'App\Models', 'class Invoice extends Model'],
    'controller' => ['mod:controller', 'InvoiceController', 'app/Http/Controllers/InvoiceController.php', 'App\Http\Controllers', 'class InvoiceController'],
    'request' => ['mod:request', 'StoreInvoiceRequest', 'app/Http/Requests/StoreInvoiceRequest.php', 'App\Http\Requests', 'class StoreInvoiceRequest extends FormRequest'],
    'factory' => ['mod:factory', 'InvoiceFactory', 'database/factories/InvoiceFactory.php', 'Database\Factories', 'class InvoiceFactory extends Factory'],
    'seeder' => ['mod:seeder', 'InvoiceSeeder', 'database/seeders/InvoiceSeeder.php', 'Database\Seeders', 'class InvoiceSeeder extends Seeder'],
    'policy' => ['mod:policy', 'InvoicePolicy', 'app/Policies/InvoicePolicy.php', 'App\Policies', 'class InvoicePolicy'],
    'provider' => ['mod:provider', 'BillingServiceProvider', 'app/Providers/BillingServiceProvider.php', 'App\Providers', 'class BillingServiceProvider extends ServiceProvider'],
    'command' => ['mod:command', 'SendInvoices', 'app/Console/Commands/SendInvoices.php', 'App\Console\Commands', 'class SendInvoices extends Command'],
    'event' => ['mod:event', 'InvoicePaid', 'app/Events/InvoicePaid.php', 'App\Events', 'class InvoicePaid'],
    'listener' => ['mod:listener', 'SendReceipt', 'app/Listeners/SendReceipt.php', 'App\Listeners', 'class SendReceipt'],
    'query (declared kind)' => ['mod:query', 'OverdueInvoices', 'app/Queries/OverdueInvoices.php', 'App\Queries', 'class OverdueInvoices'],
]);

it('generates every kind in ordinary Laravel', function (string $command, string $name, string $path, string $namespace, string $class) {
    Workspace::run('ordinary', function (Workspace $workspace) use ($command, $name, $path, $namespace, $class) {
        $workspace->artisan($command, ['name' => $name])->assertSuccessful();

        expect($workspace->files())->toBe([$path])
            ->and($workspace->read($path))
            ->toContain("namespace {$namespace};")
            ->toContain($class);
    });
})->with('ordinary kinds');

dataset('module kinds', [
    'model' => ['mod:model', 'Invoice', 'app/Modules/Billing/Models/Invoice.php', 'App\Modules\Billing\Models', 'class Invoice extends Model'],
    'controller' => ['mod:controller', 'InvoiceController', 'app/Modules/Billing/Controllers/InvoiceController.php', 'App\Modules\Billing\Controllers', 'class InvoiceController'],
    'request' => ['mod:request', 'StoreInvoiceRequest', 'app/Modules/Billing/Requests/StoreInvoiceRequest.php', 'App\Modules\Billing\Requests', 'class StoreInvoiceRequest extends FormRequest'],
    'factory' => ['mod:factory', 'InvoiceFactory', 'app/Modules/Billing/Database/Factories/InvoiceFactory.php', 'App\Modules\Billing\Database\Factories', 'class InvoiceFactory extends Factory'],
    'seeder' => ['mod:seeder', 'InvoiceSeeder', 'app/Modules/Billing/Database/Seeders/InvoiceSeeder.php', 'App\Modules\Billing\Database\Seeders', 'class InvoiceSeeder extends Seeder'],
    'policy' => ['mod:policy', 'InvoicePolicy', 'app/Modules/Billing/Policies/InvoicePolicy.php', 'App\Modules\Billing\Policies', 'class InvoicePolicy'],
    'provider' => ['mod:provider', 'BillingServiceProvider', 'app/Modules/Billing/Providers/BillingServiceProvider.php', 'App\Modules\Billing\Providers', 'class BillingServiceProvider extends ServiceProvider'],
    'event' => ['mod:event', 'InvoicePaid', 'app/Modules/Billing/Events/InvoicePaid.php', 'App\Modules\Billing\Events', 'class InvoicePaid'],
    'query (declared kind)' => ['mod:query', 'OverdueInvoices', 'app/Modules/Billing/Queries/OverdueInvoices.php', 'App\Modules\Billing\Queries', 'class OverdueInvoices'],
    'action (declared kind)' => ['mod:action', 'SendInvoice', 'app/Modules/Billing/Actions/SendInvoice.php', 'App\Modules\Billing\Actions', 'class SendInvoice'],
]);

it('generates every kind in a module', function (string $command, string $name, string $path, string $namespace, string $class) {
    Workspace::run('modules', function (Workspace $workspace) use ($command, $name, $path, $namespace, $class) {
        $workspace->artisan($command, ['name' => $name, '--in' => 'Billing'])->assertSuccessful();

        expect($workspace->files())->toBe([$path])
            ->and($workspace->read($path))
            ->toContain("namespace {$namespace};")
            ->toContain($class);
    });
})->with('module kinds');

it('takes the stem or the suffixed name alike', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:controller', ['name' => 'Invoice', '--in' => 'Billing'])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Modules/Billing/Controllers/InvoiceController.php']);
    });
});

it('places migrations in ordinary Laravel', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $workspace->artisan('mod:migration', ['name' => 'create_invoices_table'])->assertSuccessful();

        $path = $workspace->migration('database/migrations', 'create_invoices_table');

        expect(basename($path))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_invoices_table\.php$/')
            ->and($workspace->read($path))->toContain("Schema::create('invoices'");
    });
});

it('places migrations in a module', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->artisan('mod:migration', ['name' => 'create_invoices_table', '--in' => 'Billing'])->assertSuccessful();

        $path = $workspace->migration('app/Modules/Billing/Database/Migrations', 'create_invoices_table');

        expect($workspace->files())->toBe([$path]);
    });
});

it('uses the migration timestamp the native creator chooses', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        Date::setTestNow('2026-01-01 00:00:00');
        $workspace->write('database/migrations/2026_01_01_000000_create_users_table.php', '<?php return new class {};');

        try {
            $workspace->artisan('mod:migration', ['name' => 'create_invoices_table'])->assertSuccessful();
        } finally {
            Date::setTestNow();
        }

        // The native clock skips the occupied second; the resolved name follows it.
        expect($workspace->exists('database/migrations/2026_01_01_000001_create_invoices_table.php'))->toBeTrue();
    });
})->skip(fn () => version_compare(Application::VERSION, '12.0', '<'), 'collision-free migration prefixes are Laravel 12+');

it('places a feature-first model', function () {
    Workspace::run('feature-first', function (Workspace $workspace) {
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Features/Billing/Models/Invoice.php']);
    });
});

it('places a vertical-slice request and its feature-scoped model', function () {
    Workspace::run('vertical-slices', function (Workspace $workspace) {
        $workspace->artisan('mod:request', ['name' => 'Request', '--in' => 'Billing/CreateInvoice'])->assertSuccessful();
        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])->assertSuccessful();

        expect($workspace->files())->toBe(['app/Billing/CreateInvoice/Request.php', 'app/Billing/Models/Invoice.php'])
            ->and($workspace->read('app/Billing/CreateInvoice/Request.php'))
            ->toContain('namespace App\\Billing\\CreateInvoice;')
            ->toContain('class Request extends FormRequest');
    });
});
