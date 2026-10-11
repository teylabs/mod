<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Listing\LayoutInventory;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('isolates migration execution and repositories by connection and preserves the default schema', function () {
    Workspace::run('modules', function (Workspace $w) {
        config()->set('database.default', 'primary');
        foreach (['primary', 'catalogue'] as $name) {
            config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
        $w->write('database/migrations/2026_01_01_000000_create_app_table.php', migrationSource('app_rows'));
        $w->write('app/Modules/Catalogue/Database/Migrations/2026_01_01_000001_create_items_table.php', migrationSource('items'));
        $w->write('app/Modules/Billing/Database/Migrations/2026_01_01_000002_create_bills_table.php', migrationSource('bills'));
        Mod::connection('Catalogue', 'catalogue');
        DiscoveryRegistrar::register(app(), app(CompiledLayout::class), new DiscoveryOptions);
        expect($w->artisan('migrate', ['--force' => true])->exitCode)->toBe(0);
        expect(Schema::connection('primary')->hasTable('app_rows'))->toBeTrue()->and(Schema::connection('primary')->hasTable('bills'))->toBeTrue();
        expect(Schema::connection('catalogue')->hasTable('items'))->toBeFalse();
        expect($w->artisan('migrate', ['--database' => 'catalogue', '--force' => true])->exitCode)->toBe(0);
        expect(Schema::connection('catalogue')->hasTable('items'))->toBeTrue()->and(Schema::connection('catalogue')->hasTable('app_rows'))->toBeFalse();
        expect(DB::connection('catalogue')->table('migrations')->pluck('migration')->all())->toBe(['2026_01_01_000001_create_items_table']);
        expect($w->artisan('migrate:rollback', ['--database' => 'catalogue', '--force' => true])->exitCode)->toBe(0);
        expect(Schema::connection('primary')->hasTable('bills'))->toBeTrue()->and(Schema::connection('catalogue')->hasTable('items'))->toBeFalse();
        expect($w->artisan('migrate:fresh', ['--database' => 'catalogue', '--force' => true])->exitCode)->toBe(0);
        expect(Schema::connection('primary')->hasTable('app_rows'))->toBeTrue()->and(Schema::connection('catalogue')->hasTable('items'))->toBeTrue();
        expect((new LayoutInventory(app()))->read()['connections'])->toContain(['group' => 'Catalogue', 'connection' => 'catalogue', 'declared' => true]);
    });
});

it('generates a migration with its declared connection', function () {
    Workspace::run('modules', function (Workspace $w) {
        config()->set('database.connections.catalogue', ['driver' => 'sqlite', 'database' => ':memory:']);
        Mod::connection('Catalogue', 'catalogue');
        $result = $w->artisan('mod:migration', ['name' => 'Catalogue:create_items_table']);
        expect($result->exitCode)->toBe(0);
        $files = glob($w->root->path.'/app/Modules/Catalogue/Database/Migrations/*.php');
        expect($files)->toHaveCount(1)->and(file_get_contents($files[0]))->toContain("protected \$connection = 'catalogue';");
    });
});

it('defaults models and factories to the module connection and honours explicit overrides', function () {
    Workspace::run('modules', function (Workspace $w) {
        Mod::connection('Catalogue', 'catalogue');
        $class = 'App\\Modules\\Catalogue\\Models\\Item'.bin2hex(random_bytes(4));
        eval('namespace App\\Modules\\Catalogue\\Models; class '.class_basename($class).' extends \\Illuminate\\Database\\Eloquent\\Model { use \\Tey\\Mod\\Database\\UsesGroupConnection; protected $guarded = []; }');
        if (! is_subclass_of($class, Model::class)) {
            throw new LogicException('The fixture model must extend Eloquent.');
        }
        $factory = new class extends Factory
        {
            protected $model;

            public function definition(): array
            {
                return [];
            }

            public function modelName()
            {
                return config('test_model');
            }
        };
        config()->set('test_model', $class);
        expect((new $class)->getConnectionName())->toBe('catalogue')->and($factory->make()->getConnectionName())->toBe('catalogue');
        expect($factory->connection('primary')->make()->getConnectionName())->toBe('primary');
        expect((new $class)->setConnection('primary')->getConnectionName())->toBe('primary');
    });
});

it('scopes seeders and restores the default even on failure', function () {
    Workspace::run('modules', function (Workspace $w) {
        Mod::connection('Catalogue', 'catalogue');
        $class = 'App\\Modules\\Catalogue\\Database\\Seeders\\Items'.bin2hex(random_bytes(4)).'Seeder';
        eval('namespace App\\Modules\\Catalogue\\Database\\Seeders; class '.class_basename($class).' extends \\Illuminate\\Database\\Seeder { use \\Tey\\Mod\\Database\\SeedsGroupConnection; public function run(): void { config()->set("seeder_connection", app("db")->getDefaultConnection()); throw new \\RuntimeException("seeder failed"); } }');
        $previous = DB::getDefaultConnection();
        expect(fn () => (new $class)())->toThrow(RuntimeException::class, 'seeder failed');
        expect(config('seeder_connection'))->toBe('catalogue')->and(DB::getDefaultConnection())->toBe($previous);
    });
});

it('refuses conflicting missing and incomplete connection declarations', function () {
    Workspace::run('modules', function (Workspace $w) {
        Mod::connection('Catalogue', 'missing');
        expect(fn () => (new LayoutInventory(app()))->read())->toThrow(LogicException::class, 'not configured');
        expect(fn () => Mod::connection('Catalogue', 'other'))->toThrow(LogicException::class, 'consistently');
    });
});

it('keeps a connected group with no migrations from running app migrations', function () {
    Workspace::run('modules', function (Workspace $w) {
        config()->set('database.connections.catalogue', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $w->write('database/migrations/2026_01_01_000000_create_app_table.php', migrationSource('app_rows'));
        Mod::connection('Catalogue', 'catalogue');
        DiscoveryRegistrar::register(app(), app(CompiledLayout::class), new DiscoveryOptions);
        expect($w->artisan('migrate', ['--database' => 'catalogue', '--force' => true])->exitCode)->toBe(0);
        expect(Schema::connection('catalogue')->hasTable('app_rows'))->toBeFalse();
    });
});

function migrationSource(string $table): string
{
    return "<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create('{$table}', function (\\Illuminate\\Database\\Schema\\Blueprint \$t) { \$t->id(); }); } public function down(): void { \\Illuminate\\Support\\Facades\\Schema::dropIfExists('{$table}'); } };";
}
