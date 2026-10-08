<?php

use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * Acceptance, layout 2: feature-first (app/Features/<Feature>/...).
 * The built-in `features` layout includes event and listener kinds; they are
 * added from AppServiceProvider::boot(), exactly as a host would.
 */

function featureFirstLayout(): LayoutUnderTest
{
    return new LayoutUnderTest('features', fn () => Mod::layout('features')
        ->kind('event', in: 'Features/{feature}/Events')
        ->kind('listener', in: 'Features/{feature}/Listeners'));
}

it('runs the whole loop on feature-first', function () {
    AcceptanceApp::run(featureFirstLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $in = ['--in' => 'Billing'];
        $ctx = ['feature' => 'Billing'];
        $ns = 'App\Features\Billing';
        $app->boot();

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--factory' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:controller', ['name' => "Invoice{$t}Controller", '--model' => "Invoice{$t}", '--requests' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", ...$in])->assertSuccessful();
        $app->artisan('mod:event', ['name' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:listener', ['name' => "Send{$t}Receipt", '--event' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:command', ['name' => "Prune{$t}Invoices"])->assertSuccessful();
        // query and validator: custom kinds the built-in layout declares as data.
        $app->artisan('mod:query', ['name' => "Overdue{$t}Invoices", ...$in])->assertSuccessful();
        $app->artisan('mod:validator', ['name' => "Invoice{$t}", ...$in])->assertSuccessful();
        $app->artisan('mod:migration', ['name' => 'create_invoices_table', ...$in])->assertSuccessful();

        $migration = $app->migration('app/Features/Billing/Database/Migrations', 'create_invoices_table');

        $generated = [
            "app/Console/Commands/Prune{$t}Invoices.php" => ['command', [], "App\\Console\\Commands\\Prune{$t}Invoices"],
            "app/Features/Billing/Database/Factories/Invoice{$t}Factory.php" => ['factory', $ctx, "{$ns}\\Database\\Factories\\Invoice{$t}Factory"],
            "app/Features/Billing/Events/Invoice{$t}Paid.php" => ['event', $ctx, "{$ns}\\Events\\Invoice{$t}Paid"],
            "app/Features/Billing/Http/Controllers/Invoice{$t}Controller.php" => ['controller', $ctx, "{$ns}\\Http\\Controllers\\Invoice{$t}Controller"],
            "app/Features/Billing/Http/Requests/StoreInvoice{$t}Request.php" => ['request', $ctx, "{$ns}\\Http\\Requests\\StoreInvoice{$t}Request"],
            "app/Features/Billing/Http/Requests/UpdateInvoice{$t}Request.php" => ['request', $ctx, "{$ns}\\Http\\Requests\\UpdateInvoice{$t}Request"],
            "app/Features/Billing/Listeners/Send{$t}Receipt.php" => ['listener', $ctx, "{$ns}\\Listeners\\Send{$t}Receipt"],
            "app/Features/Billing/Models/Invoice{$t}.php" => ['model', $ctx, "{$ns}\\Models\\Invoice{$t}"],
            "app/Features/Billing/Providers/Billing{$t}ServiceProvider.php" => ['provider', $ctx, "{$ns}\\Providers\\Billing{$t}ServiceProvider"],
            "app/Features/Billing/Queries/Overdue{$t}Invoices.php" => ['query', $ctx, "{$ns}\\Queries\\Overdue{$t}Invoices"],
            "app/Features/Billing/Validation/Invoice{$t}Validator.php" => ['validator', $ctx, "{$ns}\\Validation\\Invoice{$t}Validator"],
            $migration => ['migration', $ctx, null],
        ];
        ksort($generated);

        expect($app->files())->toBe(array_keys($generated));

        // Relations: the factory sits in the feature (cross-root for Laravel's convention), so the pair is linked explicitly.
        expect($app->read("app/Features/Billing/Models/Invoice{$t}.php"))
            ->toContain("HasFactory<\\{$ns}\\Database\\Factories\\Invoice{$t}Factory>")
            ->toContain('function newFactory()')
            ->and($app->read("app/Features/Billing/Database/Factories/Invoice{$t}Factory.php"))->toContain("protected \$model = \\{$ns}\\Models\\Invoice{$t}::class;")
            ->and($app->read("app/Features/Billing/Http/Controllers/Invoice{$t}Controller.php"))
            ->toContain("use {$ns}\\Http\\Requests\\StoreInvoice{$t}Request;")
            ->toContain("use {$ns}\\Models\\Invoice{$t};");

        foreach ($generated as $path => [$kind, $context, $fqcn]) {
            $app->assertOwned($path, $kind, $context, $fqcn);
        }

        $hand = $app->handWrite("app/Features/Billing/Listeners/Audit{$t}Payment.php", "{$ns}\\Listeners", <<<PHP
            use {$ns}\\Events\\Invoice{$t}Paid;

            class Audit{$t}Payment
            {
                public function handle(Invoice{$t}Paid \$event): void {}
            }
            PHP);
        $app->assertOwned($hand, 'listener', $ctx, "{$ns}\\Listeners\\Audit{$t}Payment");

        $app->boot();
        $cold = $app->discovery()->inventory();
        $event = "{$ns}\\Events\\Invoice{$t}Paid";

        expect($app->discovery()->source())->toBe('scan')
            ->and($cold->classes(DiscoveryType::Provider))->toBe(["{$ns}\\Providers\\Billing{$t}ServiceProvider"])
            ->and($cold->classes(DiscoveryType::Command))->toBe(["App\\Console\\Commands\\Prune{$t}Invoices"])
            ->and($cold->classes(DiscoveryType::Listener))->toBe(["{$ns}\\Listeners\\Audit{$t}Payment", "{$ns}\\Listeners\\Send{$t}Receipt"])
            ->and($cold->ofType(DiscoveryType::Listener)[0]->context)->toBe($ctx)
            ->and($cold->rejections)->toBe([]);

        $assertRegistered = function () use ($app, $t, $ns, $event) {
            expect($app->app()->getProvider("{$ns}\\Providers\\Billing{$t}ServiceProvider"))->not->toBeNull()
                ->and($app->hasArtisanCommand("App\\Console\\Commands\\Prune{$t}Invoices"))->toBeTrue()
                ->and($app->app()->make('events')->getRawListeners()[$event] ?? [])->toEqualCanonicalizing([
                    "{$ns}\\Listeners\\Audit{$t}Payment@handle",
                    "{$ns}\\Listeners\\Send{$t}Receipt@handle",
                ]);
        };
        $assertRegistered();

        $app->artisan('mod:discovery-cache')->assertSuccessful();
        $app->boot();

        expect($app->discovery()->source())->toBe('cache')
            ->and($app->discovery()->inventory()->toArray())->toBe($cold->toArray());
        $assertRegistered();
    });
});

it('refuses, rejects and reports on feature-first', function () {
    AcceptanceApp::run(featureFirstLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        $app->artisan('mod:provider', ['name' => "Billing{$t}", '--in' => 'Billing'])->assertSuccessful();

        $app->artisan('mod:provider', ['name' => "Billing{$t}", '--in' => 'Billing'])
            ->expectsOutputToContain("path collision: app/Features/Billing/Providers/Billing{$t}ServiceProvider.php already exists")
            ->assertSuccessful();

        $app->artisan('mod:seeder', ['name' => 'Anything'])->expectsOutputToContain('[feature]')->assertFailed();

        $app->artisan('mod:model', ['name' => "Invoice{$t}"])
            ->expectsOutputToContain('requires a [feature] placement value; pass it with --in.')
            ->assertFailed();
        $app->artisan('mod:command', ['name' => "Prune{$t}", '--in' => 'Billing'])->assertSuccessful();

        // Non-registration: a plain class where providers live; not owned: the excluded shared root.
        $app->handWrite("app/Features/Billing/Providers/Plain{$t}ServiceProvider.php", 'App\Features\Billing\Providers', "class Plain{$t}ServiceProvider {}");
        $app->handWrite("app/Support/Shared{$t}ServiceProvider.php", 'App\Support', "class Shared{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");

        $app->boot();
        $inventory = $app->discovery()->inventory();

        expect($inventory->classes(DiscoveryType::Provider))->toBe(["App\\Features\\Billing\\Providers\\Billing{$t}ServiceProvider"])
            ->and($inventory->rejection("app/Features/Billing/Providers/Plain{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::Ineligible)
            ->and($inventory->rejection("app/Support/Shared{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::NotOwned)
            ->and($inventory->rejection("app/Support/Shared{$t}ServiceProvider.php")?->detail)->toContain('excluded')
            ->and($app->app()->getProvider("App\\Support\\Shared{$t}ServiceProvider"))->toBeNull();
    });
});
