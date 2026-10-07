<?php

use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Relation\RelationResolver;
use Tey\Mod\Relation\RelationStatus;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * M2.4 acceptance, layout 3: vertical slices (app/<Feature>/<Slice>/...).
 * Slice classes have fixed basenames; the slice message is called Command
 * and must never become an Artisan command. Provider, event and listener
 * kinds extend the built-in `slices` layout from AppServiceProvider::boot().
 */

function verticalSlicesLayout(): LayoutUnderTest
{
    return new LayoutUnderTest('slices', fn () => Mod::layout('slices')
        ->kind('provider', in: '{feature}/Providers', suffix: 'ServiceProvider')
        ->kind('event', in: '{feature}/Events')
        ->kind('listener', in: '{feature}/Listeners'));
}

it('runs the whole loop on vertical slices', function () {
    AcceptanceApp::run(verticalSlicesLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $slice = "CreateInvoice{$t}";
        $inSlice = ['--in' => "Billing/{$slice}"];
        $inFeature = ['--in' => 'Billing'];
        $sliceCtx = ['feature' => 'Billing', 'slice' => $slice];
        $featureCtx = ['feature' => 'Billing'];
        $app->boot();

        foreach (['message' => 'Command', 'handler' => 'Handler', 'request' => 'Request', 'validator' => 'Validator', 'query' => 'Query'] as $kind => $name) {
            $app->artisan("mod:{$kind}", ['name' => $name, ...$inSlice])->assertSuccessful();
        }
        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--factory' => true, ...$inFeature])->assertSuccessful();
        $app->artisan('mod:provider', ['name' => "Billing{$t}", ...$inFeature])->assertSuccessful();
        $app->artisan('mod:event', ['name' => "Invoice{$t}Paid", ...$inFeature])->assertSuccessful();
        $app->artisan('mod:listener', ['name' => "Send{$t}Receipt", '--event' => "Invoice{$t}Paid", ...$inFeature])->assertSuccessful();
        $app->artisan('mod:command', ['name' => "Prune{$t}Invoices"])->assertSuccessful();
        $app->artisan('mod:migration', ['name' => 'create_invoices_table', ...$inFeature])->assertSuccessful();

        $migration = $app->migration('app/Billing/Database/Migrations', 'create_invoices_table');
        $sliceNs = "App\\Billing\\{$slice}";

        $generated = [
            "app/Billing/{$slice}/Command.php" => ['message', $sliceCtx, "{$sliceNs}\\Command"],
            "app/Billing/{$slice}/Handler.php" => ['handler', $sliceCtx, "{$sliceNs}\\Handler"],
            "app/Billing/{$slice}/Query.php" => ['query', $sliceCtx, "{$sliceNs}\\Query"],
            "app/Billing/{$slice}/Request.php" => ['request', $sliceCtx, "{$sliceNs}\\Request"],
            "app/Billing/{$slice}/Validator.php" => ['validator', $sliceCtx, "{$sliceNs}\\Validator"],
            "app/Billing/Database/Factories/Invoice{$t}Factory.php" => ['factory', $featureCtx, "App\\Billing\\Database\\Factories\\Invoice{$t}Factory"],
            "app/Billing/Events/Invoice{$t}Paid.php" => ['event', $featureCtx, "App\\Billing\\Events\\Invoice{$t}Paid"],
            "app/Billing/Listeners/Send{$t}Receipt.php" => ['listener', $featureCtx, "App\\Billing\\Listeners\\Send{$t}Receipt"],
            "app/Billing/Models/Invoice{$t}.php" => ['model', $featureCtx, "App\\Billing\\Models\\Invoice{$t}"],
            "app/Billing/Providers/Billing{$t}ServiceProvider.php" => ['provider', $featureCtx, "App\\Billing\\Providers\\Billing{$t}ServiceProvider"],
            "app/Console/Commands/Prune{$t}Invoices.php" => ['command', [], "App\\Console\\Commands\\Prune{$t}Invoices"],
            $migration => ['migration', $featureCtx, null],
        ];
        ksort($generated);

        expect($app->files())->toBe(array_keys($generated))
            ->and($app->read("app/Billing/Models/Invoice{$t}.php"))->toContain("HasFactory<\\App\\Billing\\Database\\Factories\\Invoice{$t}Factory>")
            ->and($app->read("app/Billing/{$slice}/Request.php"))->toContain('class Request extends FormRequest');

        foreach ($generated as $path => [$kind, $context, $fqcn]) {
            $app->assertOwned($path, $kind, $context, $fqcn);
        }

        // Relations from the generated handler: its request (same slice) and the request's feature-scoped model.
        $handler = $app->mapPath("app/Billing/{$slice}/Handler.php")->artifact;
        $relations = new RelationResolver($app->preset, new PlacementResolver($app->preset));
        $request = $relations->resolve($handler ?? throw new RuntimeException('unmapped handler'), 'request');
        $model = $relations->resolve($request->target ?? throw new RuntimeException('unresolved request'), 'model', "Invoice{$t}");

        expect($request->status)->toBe(RelationStatus::Resolved)
            ->and($request->target->fqcn())->toBe("{$sliceNs}\\Request")
            ->and($model->status)->toBe(RelationStatus::Resolved)
            ->and($model->target?->fqcn())->toBe("App\\Billing\\Models\\Invoice{$t}");

        $hand = $app->handWrite("app/Billing/Listeners/Audit{$t}Payment.php", 'App\Billing\Listeners', <<<PHP
            use App\\Billing\\Events\\Invoice{$t}Paid;

            class Audit{$t}Payment
            {
                public function __invoke(Invoice{$t}Paid \$event): void {}
            }
            PHP);
        $app->assertOwned($hand, 'listener', $featureCtx, "App\\Billing\\Listeners\\Audit{$t}Payment");

        $app->boot();
        $cold = $app->discovery()->inventory();
        $event = "App\\Billing\\Events\\Invoice{$t}Paid";

        // Only the genuine Artisan command registers; the slice message called Command never does.
        expect($app->discovery()->source())->toBe('scan')
            ->and($cold->classes(DiscoveryType::Provider))->toBe(["App\\Billing\\Providers\\Billing{$t}ServiceProvider"])
            ->and($cold->classes(DiscoveryType::Command))->toBe(["App\\Console\\Commands\\Prune{$t}Invoices"])
            ->and($cold->classes(DiscoveryType::Listener))->toBe(["App\\Billing\\Listeners\\Audit{$t}Payment", "App\\Billing\\Listeners\\Send{$t}Receipt"])
            ->and($cold->rejections)->toBe([]);

        $assertRegistered = function () use ($app, $t, $sliceNs, $event) {
            expect($app->app()->getProvider("App\\Billing\\Providers\\Billing{$t}ServiceProvider"))->not->toBeNull()
                ->and($app->hasArtisanCommand("App\\Console\\Commands\\Prune{$t}Invoices"))->toBeTrue()
                ->and($app->hasArtisanCommand("{$sliceNs}\\Command"))->toBeFalse()
                ->and($app->app()->make('events')->getRawListeners()[$event] ?? [])->toEqualCanonicalizing([
                    "App\\Billing\\Listeners\\Audit{$t}Payment@__invoke",
                    "App\\Billing\\Listeners\\Send{$t}Receipt@handle",
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

it('refuses, rejects and reports on vertical slices', function () {
    AcceptanceApp::run(verticalSlicesLayout(), function (AcceptanceApp $app) {
        $t = $app->tag;
        $slice = "CreateInvoice{$t}";
        $app->boot();

        $app->artisan('mod:message', ['name' => 'Command', '--in' => "Billing/{$slice}"])->assertSuccessful();

        $app->artisan('mod:message', ['name' => 'Command', '--in' => "Billing/{$slice}"])
            ->expectsOutputToContain("path collision: app/Billing/{$slice}/Command.php already exists")
            ->assertFailed();

        // No controller kind in this layout.
        expect(fn () => $app->artisan('mod:controller', ['name' => 'Anything']))->toThrow(CommandNotFoundException::class);

        $app->artisan('mod:model', ['name' => "Invoice{$t}", '--in' => "Billing/{$slice}"])->expectsOutputToContain('[slice]')->assertFailed();
        $app->artisan('mod:handler', ['name' => 'Handler', '--in' => 'Billing'])->expectsOutputToContain('[slice]')->assertFailed();

        // Excluded root: a real provider in app/Providers is not owned, never registered.
        $app->handWrite("app/Providers/Global{$t}ServiceProvider.php", 'App\Providers', "class Global{$t}ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {}");
        // Ambiguity: a listener basenamed Handler is also a handler of a slice called "Listeners".
        $app->handWrite('app/Billing/Listeners/Handler.php', 'App\Billing\Listeners', 'class Handler {}');

        $app->boot();
        $inventory = $app->discovery()->inventory();
        $ambiguous = $inventory->rejection('app/Billing/Listeners/Handler.php');

        expect($inventory->isEmpty())->toBeTrue()
            ->and($inventory->rejection("app/Providers/Global{$t}ServiceProvider.php")?->reason)->toBe(RejectionReason::NotOwned)
            ->and($app->app()->getProvider("App\\Providers\\Global{$t}ServiceProvider"))->toBeNull()
            ->and($ambiguous?->reason)->toBe(RejectionReason::Ambiguous)
            ->and($ambiguous?->candidates)->toHaveCount(2)
            ->and($inventory->rejection("app/Billing/{$slice}/Command.php"))->toBeNull();

        // Even a host that maps the message kind to Artisan commands gets no command: eligibility is semantic.
        $app->boot(['kinds' => ['message' => 'command']]);
        $mapped = $app->discovery()->inventory();

        expect($mapped->classes(DiscoveryType::Command))->toBe([])
            ->and($mapped->rejection("app/Billing/{$slice}/Command.php")?->reason)->toBe(RejectionReason::Ineligible)
            ->and($app->hasArtisanCommand("App\\Billing\\{$slice}\\Command"))->toBeFalse();
    });
});
