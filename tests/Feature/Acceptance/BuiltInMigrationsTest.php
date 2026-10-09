<?php

use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;

/*
 * Every built-in layout with a migration kind supports `mod:model -m`, and the
 * migrations it places are seen by `php artisan migrate`.
 */

it('creates the migration next to the model in every built-in layout', function (string $layout, string $directory) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($directory) {
        $in = $app->preset->dimensionNames() === [] ? [] : ['--in' => 'Billing'];
        $app->boot();

        $app->artisan('mod:model', ['name' => 'Invoice', '--migration' => true, ...$in])->assertSuccessful();

        $migration = $app->migration($directory, 'create_invoices_table');

        expect($app->files())->toContain($migration)
            ->and($app->read($migration))->toContain("Schema::create('invoices'");
    });
})->with([
    'laravel' => ['laravel', 'database/migrations'],
    'features' => ['features', 'app/Features/Billing/Database/Migrations'],
    'slices' => ['slices', 'app/Billing/Database/Migrations'],
    'type-first' => ['type-first', 'database/migrations/Billing'],
    'modules' => ['modules', 'app/Modules/Billing/Database/Migrations'],
]);

it('loads the migration directories a layout places so migrate sees them', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $app->write('app/Modules/Billing/Database/Migrations/2024_01_01_000000_create_invoices_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('invoices', function (Blueprint $table) {
                    $table->id();
                });
            }
        };
        PHP);
        $app->boot();

        expect($app->discovery()->inventory()->directories('migration'))->toBe(['app/Modules/Billing/Database/Migrations']);

        $result = $app->artisan('migrate', ['--pretend' => true]);
        $result->assertSuccessful();

        expect($result->output)->toContain('create_invoices_table');
    });
});

it('leaves migration directories alone when the kind is opted out of discovery', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $app->write('app/Modules/Billing/Database/Migrations/2024_01_01_000000_create_invoices_table.php', '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };');
        $app->boot(['file_types' => ['migration' => false]]);

        expect($app->discovery()->inventory()->directories('migration'))->toBe([]);

        $result = $app->artisan('migrate', ['--pretend' => true]);
        $result->assertSuccessful();

        expect($result->output)->not->toContain('create_invoices_table');
    });
});
