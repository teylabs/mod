<?php

use Symfony\Component\Process\Process;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('preserves an explicit house factory model property', function (bool $scaffold, string $eol) {
    Workspace::run(null, function (Workspace $w) use ($scaffold, $eol) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('house-factory', fn (Scaffold $s) => $s->makes('factory', name: '{name}Factory', stub: 'house'));
        $stub = <<<'STUB'
        <?php
        namespace {{ namespace }};
        use Illuminate\Database\Eloquent\Factories\Factory;
        class {{ class }} extends Factory
        {
            protected $model = \App\Models\HouseModel::class;
            public function definition(): array { return []; }
        }
        STUB;
        $w->write($scaffold ? 'stubs/mod.factory.house.stub' : 'stubs/mod.factory.stub', str_replace("\n", $eol, $stub));
        $w->artisan($scaffold ? 'mod:house-factory' : 'mod:factory', ['name' => $scaffold ? 'Billing:Invoice' : 'Billing:InvoiceFactory', ...($scaffold ? [] : ['--model' => 'Invoice'])])->assertSuccessful();
        $source = $w->read('app/Modules/Billing/Database/Factories/InvoiceFactory.php');
        expect(substr_count($source, 'protected $model'))->toBe(1)
            ->and($source)->toContain('protected $model = \\App\\Models\\HouseModel::class;');
        PhpToken::tokenize($source, TOKEN_PARSE);
        $lint = new Process([PHP_BINARY, '-l', $w->root->path('app/Modules/Billing/Database/Factories/InvoiceFactory.php')]);
        $lint->run();
        expect($lint->isSuccessful())->toBeTrue($lint->getOutput().$lint->getErrorOutput());
        $w->artisan('mod:job', ['name' => 'Billing:AfterFactory'])->assertSuccessful();
    });
})->with([true, false])->with(["\n", "\r\n"]);

it('does not mistake comments strings or method variables for factory properties', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod.factory.stub', <<<'STUB'
        <?php
        namespace {{ namespace }};
        use Illuminate\Database\Eloquent\Factories\Factory;
        class {{ class }} extends Factory
        {
            // protected $model = Other::class;
            public function definition(): array { $model = 'protected $model'; return []; }
        }
        STUB);
        $w->artisan('mod:factory', ['name' => 'Billing:InvoiceFactory', '--model' => 'Invoice'])->assertSuccessful();
        expect($w->read('app/Modules/Billing/Database/Factories/InvoiceFactory.php'))->toContain('protected $model = \\App\\Modules\\Billing\\Models\\Invoice::class;');
    });
});

it('preserves house model factory members and imports alongside placeholders', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod.model.stub', <<<'STUB'
        <?php
        namespace {{ namespace }};
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Factories\HasFactory;
        {{ factoryImport }}
        class {{ class }} extends Model
        {
            use HasFactory;
            {{ factory }}
            protected static function newFactory() { return \App\HouseFactory::new(); }
        }
        STUB);
        $w->artisan('mod:model', ['name' => 'Billing:Invoice', '--factory' => true])->assertSuccessful();
        $source = $w->read('app/Modules/Billing/Models/Invoice.php');
        expect(substr_count($source, 'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;'))->toBe(1)
            ->and(substr_count($source, 'use HasFactory;'))->toBe(1)
            ->and(substr_count($source, 'function newFactory'))->toBe(1)
            ->and($source)->toContain('return \\App\\HouseFactory::new();');
        PhpToken::tokenize($source, TOKEN_PARSE);
    });
});

it('plans a direct migration with an accepted timestamp and renders its house variant', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        app()->bind(ModMigrationCreator::class, fn () => new class(app('files'), $w->root->path('stubs')) extends ModMigrationCreator
        {
            public function datePrefixFor(string $directory): string
            {
                return app(ScaffoldExecution::class)->planning ? '2026_10_09_120000' : '2026_10_09_120100';
            }
        });
        Mod::scaffold('schema', fn (Scaffold $s) => $s->makes('model')->makes('migration', name: 'create_invoices_table', stub: 'house', options: ['--create' => 'invoices']));
        $w->write('stubs/mod.migration.house.stub', <<<'STUB'
        <?php
        use Illuminate\Database\Migrations\Migration;
        return new class extends Migration {
            public function up(): void { /* {{ table }} {{ model.fqcn }} house */ }
        };
        STUB);
        $result = $w->artisan('mod:schema', ['name' => 'Billing:Invoice'])->assertSuccessful();
        $path = 'app/Modules/Billing/Database/Migrations/2026_10_09_120000_create_invoices_table.php';
        $result->expectsOutputToContain('will write 2 files')->expectsOutputToContain('Migration ['.$path.'] created successfully.');
        expect($w->migration('app/Modules/Billing/Database/Migrations', 'create_invoices_table'))->toBe($path)
            ->and($w->read($path))->toContain('invoices App\\Modules\\Billing\\Models\\Invoice house');
    });
});

it('refuses missing migration variants and later collisions before any scaffold writes', function (bool $missing) {
    Workspace::run(null, function (Workspace $w) use ($missing) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('schema', fn (Scaffold $s) => $s->makes('model')->makes('migration', name: 'create_invoices_table', stub: $missing ? 'absent' : null)->makes('job'));
        if (! $missing) {
            $w->write('app/Modules/Billing/Jobs/Invoice.php', 'existing');
        }
        $before = $w->files();
        $w->artisan('mod:schema', ['name' => 'Billing:Invoice'])->assertFailed()->expectsOutputToContain('Nothing was written.');
        expect($w->files())->toBe($before);
    });
})->with([true, false]);

it('does not duplicate a house base import at its placeholder', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        $w->write('stubs/mod.dto.stub', <<<'STUB'
        <?php
        namespace {{ namespace }};
        use {{ base }};
        {{ baseImport }}
        class {{ class }}{{ extends }} {}
        STUB);
        $w->artisan('mod:dto', ['name' => 'Billing:Invoice'])->assertSuccessful();
        $source = $w->read('src/Domain/Billing/Data/Invoice.php');
        expect(substr_count($source, 'use '))->toBe(1);
        PhpToken::tokenize($source, TOKEN_PARSE);
    });
});

it('keeps or overwrites a planned migration and leaves later ordinary commands native', function (bool $force) {
    Workspace::run(null, function (Workspace $w) use ($force) {
        config()->set('mod.layout', 'modules');
        app()->bind(ModMigrationCreator::class, fn () => new class(app('files'), $w->root->path('stubs')) extends ModMigrationCreator
        {
            public function datePrefixFor(string $directory): string
            {
                return '2026_10_09_120000';
            }
        });
        Mod::scaffold('schema', fn (Scaffold $s) => $s->makes('migration', name: 'create_invoices_table', stub: 'house')->makes('job'));
        $path = 'app/Modules/Billing/Database/Migrations/2026_10_09_120000_create_invoices_table.php';
        $original = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration {};';
        $w->write($path, $original);
        $w->write('stubs/mod.migration.house.stub', '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { /* house {{ table }} */ };');
        $w->artisan('mod:schema', ['name' => 'Billing:Invoice', $force ? '--force' : '--skip-existing' => true])->assertSuccessful();
        expect($w->read($path))->{$force ? 'toContain' : 'toBe'}($force ? 'house invoices' : $original)
            ->and($w->exists('app/Modules/Billing/Jobs/Invoice.php'))->toBeTrue();
        $w->artisan('mod:migration', ['name' => 'Billing:create_payments_table'])->assertSuccessful();
        $ordinary = $w->read($w->migration('app/Modules/Billing/Database/Migrations', 'create_payments_table'));
        expect($ordinary)->toContain("Schema::create('payments'");
        expect($ordinary)->not->toContain('house');
    });
})->with([true, false]);

it('publishes a missing migration variant from the native create template before generating', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('schema', fn (Scaffold $s) => $s->makes('migration', name: 'create_invoices_table', stub: 'house'));
        Examples::testCase()->artisan('mod:schema', ['name' => 'Billing:Invoice'])
            ->expectsConfirmation("The schema scaffold uses stubs/mod.migration.house.stub, which doesn't exist. Create it from the migration stub?", 'yes')
            ->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        expect($w->read('stubs/mod.migration.house.stub'))->toContain('Schema::create')
            ->and($w->read($w->migration('app/Modules/Billing/Database/Migrations', 'create_invoices_table')))->toContain("Schema::create('invoices'");
    });
});

it('adds only missing controller request imports to a house stub', function (bool $both) {
    Workspace::run(null, function (Workspace $w) use ($both) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Billing/Models/Invoice.php', '<?php namespace App\\Modules\\Billing\\Models; class Invoice {}');
        $w->write('stubs/mod.controller.stub', '<?php'."\n".'namespace {{ namespace }};'."\n".
            'use App\\Modules\\Billing\\Requests\\StoreInvoiceRequest;'."\n".
            ($both ? 'use App\\Modules\\Billing\\Requests\\UpdateInvoiceRequest;'."\n" : '').
            'use {{ namespacedRequests }}'."\n".'class {{ class }} {}');
        $w->artisan('mod:controller', ['name' => 'Billing:InvoiceController', '--model' => 'Invoice', '--requests' => true])->assertSuccessful();
        $source = $w->read('app/Modules/Billing/Controllers/InvoiceController.php');
        expect(substr_count($source, 'use App\\Modules\\Billing\\Requests\\StoreInvoiceRequest;'))->toBe(1)
            ->and(substr_count($source, 'use App\\Modules\\Billing\\Requests\\UpdateInvoiceRequest;'))->toBe(1);
        PhpToken::tokenize($source, TOKEN_PARSE);
    });
})->with([true, false]);

it('keeps migration provenance aligned with native generation after a house scaffold', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $native = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { /* native published */ };';
        $w->write('stubs/migration.stub', $native);
        $w->write('stubs/mod.migration.stub', '<?php // class generator override');
        $w->write('stubs/mod.migration.house.stub', '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { /* house */ };');
        Mod::scaffold('schema', fn (Scaffold $s) => $s->makes('migration', name: 'create_invoices_table', stub: 'house'));
        $w->artisan('mod:schema', ['name' => 'Billing:Invoice'])->assertSuccessful();
        expect($w->read($w->migration('app/Modules/Billing/Database/Migrations', 'create_invoices_table')))->toContain('/* house */');
        $before = $w->files();
        $listing = json_decode($w->artisan('mod:list', ['--json' => true, '--type' => 'migration'])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($listing['types'][0]['source'])->toBe('published stub')
            ->and($listing['types'][0]['stub'])->toBe('stubs/migration.stub')
            ->and($w->files())->toBe($before);
        $w->artisan('mod:migration', ['name' => 'Billing:adjust_invoice_schema'])->assertSuccessful();
        expect($w->read($w->migration('app/Modules/Billing/Database/Migrations', 'adjust_invoice_schema')))->toBe($native);
    });
});
