<?php

use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * Acceptance, the built-in ddd layout: domain objects in src/Domain/<Domain>,
 * application objects in app/Modules/<Domain>, nested subdomains, and the
 * DTO / view model / action stubs with their generated bases. The optional
 * packages are not installed in mod's own dev install; the "present" case
 * fakes the package detector instead of installing them.
 */

/**
 * The ddd layout with its domain root under a namespace of its own, so the
 * base classes generated here are never already loaded in this process.
 */
function isolatedDdd(string $namespace): LayoutUnderTest
{
    return new LayoutUnderTest('ddd', fn () => isolatedDomainNamespace($namespace));
}

it('places by --domain, the shorthand and nested subdomains', function () {
    AcceptanceApp::run('ddd', function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        expect($app->artisan('mod:model', ['name' => "Invoice{$t}", '--domain' => 'Billing']))
            ->toHaveGenerated("src/Domain/Billing/Models/Invoice{$t}.php", 'Domain\\Billing\\Models')
            ->and($app->artisan('mod:model', ['name' => "Billing:Payment{$t}"]))
            ->toHaveGenerated("src/Domain/Billing/Models/Payment{$t}.php", 'Domain\\Billing\\Models')
            ->and($app->artisan('mod:model', ['name' => "Report{$t}", '--domain' => 'Reporting.Internal']))
            ->toHaveGenerated("src/Domain/Reporting/Internal/Models/Report{$t}.php", 'Domain\\Reporting\\Internal\\Models')
            ->and($app->artisan('mod:model', ['name' => "Summary{$t}", '--domain' => 'Reporting/Internal']))
            ->toHaveGenerated("src/Domain/Reporting/Internal/Models/Summary{$t}.php", 'Domain\\Reporting\\Internal\\Models')
            ->and($app->artisan('mod:model', ['name' => "Reporting.Internal:Chart{$t}"]))
            ->toHaveGenerated("src/Domain/Reporting/Internal/Models/Chart{$t}.php", 'Domain\\Reporting\\Internal\\Models');

        $app->assertOwned("src/Domain/Reporting/Internal/Models/Report{$t}.php", 'model', ['domain' => 'Reporting/Internal'], "Domain\\Reporting\\Internal\\Models\\Report{$t}");

        $app->artisan('mod:model', ['name' => "Invoice{$t}"])
            ->expectsOutputToContain("mod:model needs a domain. Pass --domain=<domain>, --in=<domain>, or prefix the name: <domain>:Invoice{$t}.")
            ->assertFailed();
    });
});

it('puts controllers, requests and middleware in the application layer', function () {
    AcceptanceApp::run('ddd', function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        expect($app->artisan('mod:controller', ['name' => "Invoice{$t}", '--domain' => 'Billing']))
            ->toHaveGenerated("app/Modules/Billing/Controllers/Invoice{$t}Controller.php", 'App\\Modules\\Billing\\Controllers')
            ->and($app->artisan('mod:request', ['name' => "Billing:StoreInvoice{$t}"]))
            ->toHaveGenerated("app/Modules/Billing/Requests/StoreInvoice{$t}Request.php", 'App\\Modules\\Billing\\Requests')
            ->and($app->artisan('mod:middleware', ['name' => "Billing:EnsureInvoice{$t}"]))
            ->toHaveGenerated("app/Modules/Billing/Middleware/EnsureInvoice{$t}.php", 'App\\Modules\\Billing\\Middleware');

        $app->artisan('mod:model', ['name' => "Order{$t}", '--domain' => 'Billing', '--controller' => true, '--resource' => true, '--requests' => true])->assertSuccessful();

        expect($app->read("app/Modules/Billing/Controllers/Order{$t}Controller.php"))
            ->toContain("use Domain\\Billing\\Models\\Order{$t};")
            ->toContain("use App\\Modules\\Billing\\Requests\\StoreOrder{$t}Request;");
    });
});

it('generates a DTO and its base without the optional packages, and the DTO round-trips', function () {
    $ns = 'Domain'.bin2hex(random_bytes(4));

    AcceptanceApp::run(isolatedDdd($ns), function (AcceptanceApp $app) use ($ns) {
        $app->boot();

        $result = $app->artisan('mod:dto', ['name' => 'InvoiceData', '--domain' => 'Billing']);

        expect($result)->toHaveGenerated('src/Domain/Billing/Data/InvoiceData.php', "{$ns}\\Billing\\Data")
            ->and($result->output)->toContain("Created base class {$ns}\\Shared\\Data\\DataTransferObject [src/Domain/Shared/Data/DataTransferObject.php].")
            ->and($app->root->path('src/Domain/Shared/Data/DataTransferObject.php'))->toBeValidPhp();

        $app->write('src/Domain/Billing/Data/InvoiceData.php', <<<PHP
            <?php

            namespace {$ns}\\Billing\\Data;

            use {$ns}\\Shared\\Data\\DataTransferObject;

            class InvoiceData extends DataTransferObject
            {
                public function __construct(
                    public string \$number,
                    public int \$total,
                ) {}
            }

            PHP);

        $class = "{$ns}\\Billing\\Data\\InvoiceData";
        $dto = $class::fromArray(['number' => 'INV-1', 'total' => 1200, 'ignored' => true]);

        expect($dto->toArray())->toBe(['number' => 'INV-1', 'total' => 1200]);

        $second = $app->artisan('mod:data', ['name' => 'Billing:LineData']);

        expect($second)->toHaveGenerated('src/Domain/Billing/Data/LineData.php')
            ->and($second->output)->not->toContain('Created base class');
    });
});

it('generates a view model and its base without the optional packages', function () {
    $ns = 'Domain'.bin2hex(random_bytes(4));

    AcceptanceApp::run(isolatedDdd($ns), function (AcceptanceApp $app) use ($ns) {
        $app->boot();

        $result = $app->artisan('mod:view-model', ['name' => 'ShowInvoice', '--domain' => 'Billing']);

        expect($result)->toHaveGenerated('src/Domain/Billing/ViewModels/ShowInvoice.php', "{$ns}\\Billing\\ViewModels")
            ->and($result->output)->toContain("Created base class {$ns}\\Shared\\ViewModels\\ViewModel [src/Domain/Shared/ViewModels/ViewModel.php].")
            ->and($app->root->path('src/Domain/Shared/ViewModels/ViewModel.php'))->toBeValidPhp()
            ->and(class_exists("{$ns}\\Billing\\ViewModels\\ShowInvoice"))->toBeTrue();
    });
});

it('uses the optional packages when they are installed', function () {
    AcceptanceApp::run('ddd', function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot()->instance(PackageDetector::class, new class implements PackageDetector
        {
            public function isInstalled(string $package): bool
            {
                return in_array($package, ['spatie/laravel-data', 'spatie/laravel-view-models', 'lorisleiva/laravel-actions'], true);
            }

            public function classExists(string $class): bool
            {
                return class_exists($class);
            }
        });

        $dto = $app->artisan('mod:dto', ['name' => "Billing:Invoice{$t}Data"]);
        $viewModel = $app->artisan('mod:view-model', ['name' => "Billing:Show{$t}Invoice"]);
        $action = $app->artisan('mod:action', ['name' => "Billing:Pay{$t}Invoice"]);

        expect($dto)->toHaveGenerated("src/Domain/Billing/Data/Invoice{$t}Data.php")
            ->and($dto->output)->toContain('Using spatie/laravel-data (installed).')
            ->and($app->read("src/Domain/Billing/Data/Invoice{$t}Data.php"))->toContain("class Invoice{$t}Data extends Data")
            ->and($viewModel->output)->toContain('Using spatie/laravel-view-models (installed).')
            ->and($app->read("src/Domain/Billing/ViewModels/Show{$t}Invoice.php"))->toContain('use Spatie\\ViewModels\\ViewModel;')
            ->and($action->output)->toContain('Using lorisleiva/laravel-actions (installed).')
            ->and($app->read("src/Domain/Billing/Actions/Pay{$t}Invoice.php"))->toContain('use AsAction;')
            ->and(is_file($app->root->path('src/Domain/Shared/Data/DataTransferObject.php')))->toBeFalse()
            ->and(is_file($app->root->path('src/Domain/Shared/ViewModels/ViewModel.php')))->toBeFalse();
    });
});

it('names providers as given, like laravel-ddd 3.x ddd:provider and make:provider', function () {
    AcceptanceApp::run('ddd', function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        expect($app->artisan('mod:provider', ['name' => "Billing:Billing{$t}"]))
            ->toHaveGenerated("src/Domain/Billing/Providers/Billing{$t}.php", 'Domain\\Billing\\Providers')
            ->and($app->artisan('mod:provider', ['name' => "Billing:Invoicing{$t}ServiceProvider"]))
            ->toHaveGenerated("src/Domain/Billing/Providers/Invoicing{$t}ServiceProvider.php", 'Domain\\Billing\\Providers');
    });
});
