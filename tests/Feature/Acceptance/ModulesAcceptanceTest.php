<?php

use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * M2.4 acceptance, layout 5: the cookbook's app/Modules/<Module> with flat
 * folders. app/UI and app/Support are siblings, not modules. Listener and
 * Artisan command kinds are added as preset data only.
 */

/**
 * @return array<string, mixed>
 */
function modulesLayout(): array
{
    $definition = Layouts::definition('modules');
    $definition['kinds']['listener'] = ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:listener', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Listeners']];
    $definition['kinds']['command'] = ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:command', 'root' => 'app', 'segments' => ['Modules', '{module}', 'Console']];

    return $definition;
}

it('runs the whole loop on cookbook modules', function () {
    AcceptanceApp::run(modulesLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $in = ['--in' => 'Billing'];
        $ctx = ['module' => 'Billing'];
        $ns = 'App\Modules\Billing';
        $app->boot();

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--factory' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:controller', ['name' => "Invoice{$t}Controller", '--model' => "Invoice{$t}", '--requests' => true, ...$in])->assertSuccessful();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", ...$in])->assertSuccessful();
        $app->artisan('mod:event', ['name' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:listener', ['name' => "Send{$t}Receipt", '--event' => "Invoice{$t}Paid", ...$in])->assertSuccessful();
        $app->artisan('mod:command', ['name' => "Prune{$t}Invoices", ...$in])->assertSuccessful();
        // action, data and query: custom kinds declared in preset data only.
        $app->artisan('mod:action', ['name' => "Pay{$t}Invoice", ...$in])->assertSuccessful();
        $app->artisan('mod:data', ['name' => "Invoice{$t}Data", ...$in])->assertSuccessful();
        $app->artisan('mod:query', ['name' => "Overdue{$t}Invoices", ...$in])->assertSuccessful();
        $app->artisan('mod:seeder', ['name' => "Invoice{$t}", ...$in])->assertSuccessful();
        $app->artisan('mod:migration', ['name' => 'create_invoices_table', ...$in])->assertSuccessful();

        $migration = $app->migration('app/Modules/Billing/Database/Migrations', 'create_invoices_table');

        $generated = [
            "app/Modules/Billing/Actions/Pay{$t}Invoice.php" => ['action', "{$ns}\\Actions\\Pay{$t}Invoice"],
            "app/Modules/Billing/Console/Prune{$t}Invoices.php" => ['command', "{$ns}\\Console\\Prune{$t}Invoices"],
            "app/Modules/Billing/Controllers/Invoice{$t}Controller.php" => ['controller', "{$ns}\\Controllers\\Invoice{$t}Controller"],
            "app/Modules/Billing/Data/Invoice{$t}Data.php" => ['data', "{$ns}\\Data\\Invoice{$t}Data"],
            "app/Modules/Billing/Database/Factories/Invoice{$t}Factory.php" => ['factory', "{$ns}\\Database\\Factories\\Invoice{$t}Factory"],
            "app/Modules/Billing/Database/Seeders/Invoice{$t}Seeder.php" => ['seeder', "{$ns}\\Database\\Seeders\\Invoice{$t}Seeder"],
            "app/Modules/Billing/Events/Invoice{$t}Paid.php" => ['event', "{$ns}\\Events\\Invoice{$t}Paid"],
            "app/Modules/Billing/Listeners/Send{$t}Receipt.php" => ['listener', "{$ns}\\Listeners\\Send{$t}Receipt"],
            "app/Modules/Billing/Models/Invoice{$t}.php" => ['model', "{$ns}\\Models\\Invoice{$t}"],
            "app/Modules/Billing/Providers/Billing{$t}ServiceProvider.php" => ['provider', "{$ns}\\Providers\\Billing{$t}ServiceProvider"],
            "app/Modules/Billing/Queries/Overdue{$t}Invoices.php" => ['query', "{$ns}\\Queries\\Overdue{$t}Invoices"],
            "app/Modules/Billing/Requests/StoreInvoice{$t}Request.php" => ['request', "{$ns}\\Requests\\StoreInvoice{$t}Request"],
            "app/Modules/Billing/Requests/UpdateInvoice{$t}Request.php" => ['request', "{$ns}\\Requests\\UpdateInvoice{$t}Request"],
            $migration => ['migration', null],
        ];
        ksort($generated);

        expect($app->files())->toBe(array_keys($generated))
            ->and($app->read("app/Modules/Billing/Models/Invoice{$t}.php"))
            ->toContain("HasFactory<\\{$ns}\\Database\\Factories\\Invoice{$t}Factory>")
            ->toContain("return \\{$ns}\\Database\\Factories\\Invoice{$t}Factory::new();")
            ->and($app->read("app/Modules/Billing/Controllers/Invoice{$t}Controller.php"))
            ->toContain("use {$ns}\\Requests\\StoreInvoice{$t}Request;")
            ->toContain("use {$ns}\\Requests\\UpdateInvoice{$t}Request;");

        foreach ($generated as $path => [$kind, $fqcn]) {
            $app->assertOwned($path, $kind, $ctx, $fqcn);
        }

        // Hand-written: a listener, and the module routes file (a file kind mod has no generator for).
        $hand = $app->handWrite("app/Modules/Billing/Listeners/Audit{$t}Payment.php", "{$ns}\\Listeners", <<<PHP
            use {$ns}\\Events\\Invoice{$t}Paid;

            class Audit{$t}Payment
            {
                public function handle(Invoice{$t}Paid \$event): void {}
            }
            PHP);
        $app->write('app/Modules/Billing/routes/web.php', "<?php\n");
        $app->assertOwned($hand, 'listener', $ctx, "{$ns}\\Listeners\\Audit{$t}Payment");
        $app->assertOwned('app/Modules/Billing/routes/web.php', 'routes', $ctx);

        $app->boot();
        $cold = $app->discovery()->inventory();
        $event = "{$ns}\\Events\\Invoice{$t}Paid";

        expect($app->discovery()->source())->toBe('scan')
            ->and($cold->classes(DiscoveryType::Provider))->toBe(["{$ns}\\Providers\\Billing{$t}ServiceProvider"])
            ->and($cold->classes(DiscoveryType::Command))->toBe(["{$ns}\\Console\\Prune{$t}Invoices"])
            ->and($cold->classes(DiscoveryType::Listener))->toBe(["{$ns}\\Listeners\\Audit{$t}Payment", "{$ns}\\Listeners\\Send{$t}Receipt"])
            ->and(array_unique(array_map(fn ($entry) => $entry->context, $cold->entries), SORT_REGULAR))->toBe([$ctx])
            ->and($cold->rejections)->toBe([]);

        $assertRegistered = function () use ($app, $t, $ns, $event) {
            expect($app->app()->getProvider("{$ns}\\Providers\\Billing{$t}ServiceProvider"))->not->toBeNull()
                ->and($app->hasArtisanCommand("{$ns}\\Console\\Prune{$t}Invoices"))->toBeTrue()
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

it('refuses, rejects and reports on cookbook modules', function () {
    AcceptanceApp::run(modulesLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $app->boot();

        $app->artisan('mod:data', ['name' => "Invoice{$t}Data", '--in' => 'Billing'])->assertSuccessful();

        $app->artisan('mod:data', ['name' => "Invoice{$t}Data", '--in' => 'Billing'])
            ->expectsOutputToContain("path collision: app/Modules/Billing/Data/Invoice{$t}Data.php already exists")
            ->assertFailed();

        // routes is a declared file kind with no generator: deliberately no mod:routes. widget is undeclared.
        expect($app->modCommands())->not->toContain('mod:routes')
            ->and(fn () => $app->artisan('mod:routes', ['name' => 'web']))->toThrow(CommandNotFoundException::class)
            ->and(fn () => $app->artisan('mod:widget', ['name' => 'Anything']))->toThrow(CommandNotFoundException::class);

        $app->artisan('mod:model', ['name' => "Invoice{$t}"])
            ->expectsOutputToContain('requires a [module] placement value; pass it with --in.')
            ->assertFailed();
        $app->artisan('mod:model', ['name' => "Billing/Invoice{$t}"])->expectsOutputToContain('--in=<module>')->assertFailed();

        // Siblings app/UI and app/Support are not modules, even with real providers in them.
        $app->handWrite("app/Support/Shared{$t}ServiceProvider.php", 'App\Support', "class Shared{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");
        $app->handWrite("app/UI/Ui{$t}ServiceProvider.php", 'App\UI', "class Ui{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");
        // A plain class in a module's Console folder is not an Artisan command.
        $app->handWrite("app/Modules/Billing/Console/Helper{$t}.php", 'App\Modules\Billing\Console', "class Helper{$t} {}");

        $app->boot();
        $inventory = $app->discovery()->inventory();

        expect($inventory->isEmpty())->toBeTrue()
            ->and($inventory->rejection("app/Support/Shared{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::NotOwned)
            ->and($inventory->rejection("app/UI/Ui{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::NotOwned)
            ->and($inventory->rejection("app/Modules/Billing/Console/Helper{$t}.php")?->reason)->toBe(RejectionReason::Ineligible)
            ->and($app->app()->getProvider("App\\Support\\Shared{$t}ServiceProvider"))->toBeNull()
            ->and($app->app()->getProvider("App\\UI\\Ui{$t}ServiceProvider"))->toBeNull();

        // Discovery settings naming an undeclared kind fail at boot.
        expect(fn () => $app->boot(['kinds' => ['widget' => 'provider']]))->toThrow(InvalidDiscoveryConfig::class);
    });
});
