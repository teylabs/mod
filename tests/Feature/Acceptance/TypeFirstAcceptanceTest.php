<?php

use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * Acceptance, layout 4: type-first with an optional feature after the
 * kind segments (App\Models\Invoice and App\Models\Billing\Invoice under one
 * layout). Built-in kinds can also be configured from AppServiceProvider::boot().
 */

function typeFirstLayout(): LayoutUnderTest
{
    return new LayoutUnderTest('type-first', fn () => Mod::layout('type-first')
        ->kind('provider', in: 'Providers/{feature?}', suffix: 'ServiceProvider')
        ->kind('event', in: 'Events/{feature?}')
        ->kind('listener', in: 'Listeners/{feature?}')
        ->kind('command', in: 'Console/Commands'));
}

it('runs the whole loop on type-first, with and without a feature', function () {
    AcceptanceApp::run(typeFirstLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $in = ['--in' => 'Billing'];
        $ctx = ['feature' => 'Billing'];
        $app->boot();

        // The same names, unscoped and in the Billing feature: two identities.
        foreach ([[], $in] as $placement) {
            $app->artisan('mod:model', ['name' => "Invoice{$t}", '--factory' => true, ...$placement])->assertSuccessful();
            $app->artisan('mod:query', ['name' => "Overdue{$t}Invoices", ...$placement])->assertSuccessful();
        }
        $app->artisan('mod:controller', ['name' => "Invoice{$t}Controller", '--model' => "Invoice{$t}", '--requests' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", ...$in])->assertSuccessful();
        $app->artisan('mod:event', ['name' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:listener', ['name' => "Send{$t}Receipt", '--event' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:command', ['name' => "Prune{$t}Invoices"])->assertSuccessful();
        $app->artisan('mod:migration', ['name' => 'create_invoices_table', ...$in])->assertSuccessful();

        $migration = $app->migration('database/migrations/Billing', 'create_invoices_table');

        $generated = [
            "app/Console/Commands/Prune{$t}Invoices.php" => ['command', [], "App\\Console\\Commands\\Prune{$t}Invoices"],
            "app/Events/Billing/Invoice{$t}Paid.php" => ['event', $ctx, "App\\Events\\Billing\\Invoice{$t}Paid"],
            "app/Http/Controllers/Billing/Invoice{$t}Controller.php" => ['controller', $ctx, "App\\Http\\Controllers\\Billing\\Invoice{$t}Controller"],
            "app/Http/Requests/Billing/StoreInvoice{$t}Request.php" => ['request', $ctx, "App\\Http\\Requests\\Billing\\StoreInvoice{$t}Request"],
            "app/Http/Requests/Billing/UpdateInvoice{$t}Request.php" => ['request', $ctx, "App\\Http\\Requests\\Billing\\UpdateInvoice{$t}Request"],
            "app/Listeners/Billing/Send{$t}Receipt.php" => ['listener', $ctx, "App\\Listeners\\Billing\\Send{$t}Receipt"],
            "app/Models/Billing/Invoice{$t}.php" => ['model', $ctx, "App\\Models\\Billing\\Invoice{$t}"],
            "app/Models/Invoice{$t}.php" => ['model', [], "App\\Models\\Invoice{$t}"],
            "app/Providers/Billing/Billing{$t}ServiceProvider.php" => ['provider', $ctx, "App\\Providers\\Billing\\Billing{$t}ServiceProvider"],
            "app/Queries/Billing/Overdue{$t}Invoices.php" => ['query', $ctx, "App\\Queries\\Billing\\Overdue{$t}Invoices"],
            "app/Queries/Overdue{$t}Invoices.php" => ['query', [], "App\\Queries\\Overdue{$t}Invoices"],
            "database/factories/Billing/Invoice{$t}Factory.php" => ['factory', $ctx, "Database\\Factories\\Billing\\Invoice{$t}Factory"],
            "database/factories/Invoice{$t}Factory.php" => ['factory', [], "Database\\Factories\\Invoice{$t}Factory"],
            $migration => ['migration', $ctx, null],
        ];
        ksort($generated);

        // The feature factory is where Laravel's convention looks, so no explicit link is needed.
        expect($app->files())->toBe(array_keys($generated))
            ->and($app->read("app/Models/Billing/Invoice{$t}.php"))
            ->toContain("HasFactory<\\Database\\Factories\\Billing\\Invoice{$t}Factory>")
            ->and($app->read("app/Models/Billing/Invoice{$t}.php"))->not->toContain('newFactory')
            ->and($app->read("app/Http/Controllers/Billing/Invoice{$t}Controller.php"))
            ->toContain("use App\\Http\\Requests\\Billing\\StoreInvoice{$t}Request;")
            ->toContain("use App\\Models\\Billing\\Invoice{$t};");

        foreach ($generated as $path => [$kind, $context, $fqcn]) {
            $app->assertOwned($path, $kind, $context, $fqcn);
        }

        $hand = $app->handWrite("app/Listeners/Audit{$t}Payment.php", 'App\Listeners', <<<PHP
            use App\\Events\\Billing\\Invoice{$t}Paid;

            class Audit{$t}Payment
            {
                public function handle(Invoice{$t}Paid \$event): void {}
            }
            PHP);
        $app->assertOwned($hand, 'listener', [], "App\\Listeners\\Audit{$t}Payment");

        $app->boot();
        $cold = $app->discovery()->inventory();
        $event = "App\\Events\\Billing\\Invoice{$t}Paid";

        expect($app->discovery()->source())->toBe('scan')
            ->and($cold->classes(DiscoveryType::Provider))->toBe(["App\\Providers\\Billing\\Billing{$t}ServiceProvider"])
            ->and($cold->classes(DiscoveryType::Command))->toBe(["App\\Console\\Commands\\Prune{$t}Invoices"])
            ->and($cold->classes(DiscoveryType::Listener))->toBe(["App\\Listeners\\Audit{$t}Payment", "App\\Listeners\\Billing\\Send{$t}Receipt"])
            ->and(array_map(fn ($entry) => $entry->context, $cold->ofType(DiscoveryType::Listener)))->toBe([[], $ctx])
            ->and($cold->rejections)->toBe([]);

        $assertRegistered = function () use ($app, $t, $event) {
            expect($app->app()->getProvider("App\\Providers\\Billing\\Billing{$t}ServiceProvider"))->not->toBeNull()
                ->and($app->hasArtisanCommand("App\\Console\\Commands\\Prune{$t}Invoices"))->toBeTrue()
                ->and($app->app()->make('events')->getRawListeners()[$event] ?? [])->toEqualCanonicalizing([
                    "App\\Listeners\\Audit{$t}Payment@handle",
                    "App\\Listeners\\Billing\\Send{$t}Receipt@handle",
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

it('refuses, rejects and reports on type-first', function () {
    AcceptanceApp::run(typeFirstLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--in' => 'Billing'])->assertSuccessful();

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--in' => 'Billing'])
            ->expectsOutputToContain("path collision: app/Models/Billing/Invoice{$t}.php already exists")
            ->assertSuccessful();
        // The unscoped identity is a different artifact, so it is not a collision.
        $app->artisan('mod:model', ['name' => "Invoice{$t}"])->assertSuccessful();

        expect(fn () => $app->artisan('mod:widget', ['name' => 'Anything']))->toThrow(CommandNotFoundException::class);
        $app->artisan('mod:model', ['name' => "Other{$t}", '--in' => 'Billing/Extra'])->assertFailed();
        $app->artisan('mod:command', ['name' => "Prune{$t}", '--in' => 'Billing'])->assertFailed();

        // Excluded root inside a kind's folder; and a provider nested deeper than the rule reads.
        $app->handWrite("app/Models/Concerns/Plain{$t}ServiceProvider.php", 'App\Models\Concerns', "class Plain{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");
        $app->handWrite("app/Providers/Billing/Deep/Deep{$t}ServiceProvider.php", 'App\Providers\Billing\Deep', "class Deep{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");

        $app->boot();
        $inventory = $app->discovery()->inventory();

        expect($inventory->isEmpty())->toBeTrue()
            ->and($inventory->rejection("app/Models/Concerns/Plain{$t}ServiceProvider.php")?->detail)->toContain('excluded')
            ->and($inventory->rejection("app/Providers/Billing/Deep/Deep{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::NotOwned);
    });
});

it('reports a declared overlap as ambiguous until the layout gives a priority', function () {
    // A second kind that also claims app/Listeners: data, not code, creates the ambiguity.
    $layout = new LayoutUnderTest('type-first', function () {
        (typeFirstLayout()->define)();
        Mod::layout('type-first')->kind('subscriber', in: 'Listeners', suffix: 'Subscriber', command: false);
    });

    AcceptanceApp::run($layout, function (AcceptanceApp $app) {
        $t = $app->tag;
        $path = $app->handWrite("app/Listeners/Audit{$t}Subscriber.php", 'App\Listeners', <<<PHP
            class Audit{$t}Subscriber
            {
                public function handle(\\Illuminate\\Auth\\Events\\Login \$event): void {}
            }
            PHP);

        $app->boot();
        $rejection = $app->discovery()->inventory()->rejection($path);

        expect($app->discovery()->inventory()->isEmpty())->toBeTrue()
            ->and($rejection?->reason)->toBe(RejectionReason::Ambiguous)
            ->and($rejection?->candidates)->toHaveCount(5);
    });

    $prioritised = new LayoutUnderTest('type-first', function () use ($layout) {
        ($layout->define)();
        Mod::layout('type-first')->kind('listener', priority: 1);
    });

    AcceptanceApp::run($prioritised, function (AcceptanceApp $app) {
        $t = $app->tag;
        $path = $app->handWrite("app/Listeners/Audit{$t}Subscriber.php", 'App\Listeners', <<<PHP
            class Audit{$t}Subscriber
            {
                public function handle(\\Illuminate\\Auth\\Events\\Login \$event): void {}
            }
            PHP);

        $app->assertOwned($path, 'listener', [], "App\\Listeners\\Audit{$t}Subscriber");
        $app->boot();

        expect($app->discovery()->inventory()->classes(DiscoveryType::Listener))->toBe(["App\\Listeners\\Audit{$t}Subscriber"]);
    });
});
