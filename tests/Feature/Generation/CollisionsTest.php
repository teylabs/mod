<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\Root;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Collisions are diagnosed for the whole plan before anything is written.
 */

it('refuses to overwrite an existing file, and overwrites it with --force', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing'])
            ->expectsOutputToContain('app/Modules/Billing/Models/Invoice.php already exists.')
            ->doesntExpectOutputToContain('Nothing was written.')
            ->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toBe('<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--force' => true])->assertSuccessful();

        expect($workspace->read('app/Modules/Billing/Models/Invoice.php'))->toContain('class Invoice extends Model');
    });
});

it('refuses a class that already exists elsewhere, even with --force', function () {
    Workspace::run('ordinary', function (Workspace $workspace) {
        $fqcn = 'App\Models\CollidingInvoice'.bin2hex(random_bytes(4));
        $basename = class_basename($fqcn);
        $workspace->write("app/Legacy/{$basename}.php", "<?php\n\nnamespace App\\Models;\n\nclass {$basename} {}\n");
        require $workspace->root->path("app/Legacy/{$basename}.php");

        $workspace->artisan('mod:model', ['name' => $basename, '--force' => true])
            ->expectsOutputToContain("The class {$fqcn} already exists.")
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->exists("app/Models/{$basename}.php"))->toBeFalse();
    });
});

it('refuses an unloaded class in another file, even with --force', function (string $command) {
    Workspace::run(null, function (Workspace $workspace) use ($command) {
        config()->set('mod.layout', 'laravel');
        Mod::scaffold('colliding', fn (Scaffold $s) => $s->makes('model'));
        $fqcn = 'App\\Models\\UnloadedInvoice'.bin2hex(random_bytes(4));
        $basename = class_basename($fqcn);
        $path = "app/Legacy/{$basename}.php";
        $workspace->write($path, "<?php\n\nnamespace App\\Models;\n\nclass {$basename} {}\n");
        $loader = new ClassLoader;
        $loader->addClassMap([$fqcn => $workspace->root->path($path)]);
        $loader->register();
        try {
            expect(class_exists($fqcn, false))->toBeFalse();
            $workspace->artisan($command, ['name' => $basename, '--force' => true])
                ->expectsOutputToContain("The class {$fqcn} already exists.")
                ->expectsOutputToContain('Nothing was written.')
                ->assertFailed();

            expect($workspace->files())->toBe([$path]);
        } finally {
            $loader->unregister();
        }
    });
})->with(['generator' => 'mod:model', 'scaffold' => 'mod:colliding']);

it('decides test collisions by path even when the class is declared elsewhere', function (string $type, string $rootName, string $rootPath) {
    Workspace::run(null, function (Workspace $workspace) use ($type, $rootName, $rootPath) {
        config()->set('mod.layout', 'laravel');
        Mod::layout('laravel')->mounts($rootName, 'Checks\\', $rootPath, fn (Root $root) => $root
            ->generates('test', in: 'Feature')
            ->generates($type, in: 'Feature', command: 'mod:'.$type));
        $name = 'Loaded'.bin2hex(random_bytes(4));
        $workspace->write("legacy/{$name}.php", "<?php\nnamespace Checks\\Feature;\nclass {$name} {}\n");
        require $workspace->root->path("legacy/{$name}.php");

        $workspace->artisan('mod:'.$type, ['name' => $name])->assertSuccessful();
        $path = "{$rootPath}/Feature/{$name}.php";
        $workspace->write($path, '<?php // edited');
        $workspace->artisan('mod:'.$type, ['name' => $name])
            ->expectsOutputToContain($path.' already exists')->assertSuccessful();
        expect($workspace->read($path))->toBe('<?php // edited');
        $workspace->artisan('mod:'.$type, ['name' => $name, '--force' => true])->assertSuccessful();
        expect($workspace->read($path))->toContain('class '.$name);
    });
})->with([
    'test type in another root' => ['test', 'checks', 'spec'],
    'class type in a relocated tests root' => ['class', 'tests', 'spec'],
    'class type sharing the test type root' => ['class', 'checks', 'spec'],
]);

it('refuses the whole plan when a related artifact collides', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Database/Factories/InvoiceFactory.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--factory' => true, '--force' => true])
            ->expectsOutputToContain('app/Modules/Billing/Database/Factories/InvoiceFactory.php already exists.')
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        // --force covers the primary only; the model was not written either.
        expect($workspace->files())->toBe(['app/Modules/Billing/Database/Factories/InvoiceFactory.php']);
    });
});

it('refuses related artifacts that collide with each other', function () {
    $definition = Layouts::definition('modules');
    $definition['relations']['controller-update-request']['name'] = ['prefix' => 'Store'];

    Workspace::run($definition, function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', "<?php\n\nnamespace App\\Modules\\Billing\\Models;\n\nclass Invoice {}\n");

        $workspace->artisan('mod:controller', ['name' => 'Invoice', '--in' => 'Billing', '--model' => 'Invoice', '--requests' => true])
            ->expectsOutputToContain('app/Modules/Billing/Requests/StoreInvoiceRequest.php already exists.')
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->files())->toBe(['app/Modules/Billing/Models/Invoice.php']);
    });
});

it('exits 0 when every file of the plan already exists, as make:* does', function () {
    Workspace::run('modules', function (Workspace $workspace) {
        $workspace->write('app/Modules/Billing/Models/Invoice.php', '<?php // mine');
        $workspace->write('app/Modules/Billing/Database/Factories/InvoiceFactory.php', '<?php // mine');

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', '--factory' => true])
            ->expectsOutputToContain('app/Modules/Billing/Models/Invoice.php already exists.')
            ->expectsOutputToContain('app/Modules/Billing/Database/Factories/InvoiceFactory.php already exists.')
            ->doesntExpectOutputToContain('Nothing was written.')
            ->assertSuccessful();
    });
});

it('exits 1 when a file that does not exist yet is not written', function (array $existing, array $options) {
    Workspace::run('modules', function (Workspace $workspace) use ($existing, $options) {
        foreach ($existing as $path) {
            $workspace->write($path, '<?php // mine');
        }

        $workspace->artisan('mod:model', ['name' => 'Invoice', '--in' => 'Billing', ...$options])
            ->expectsOutputToContain('Nothing was written.')
            ->assertFailed();

        expect($workspace->files())->toBe($existing);
    });
})->with([
    'the factory exists, the model does not' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php'], ['--factory' => true]],
    'both exist, the migration is new' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php', 'app/Modules/Billing/Models/Invoice.php'], ['--factory' => true, '--migration' => true]],
    '--force asks to overwrite the model' => [['app/Modules/Billing/Database/Factories/InvoiceFactory.php', 'app/Modules/Billing/Models/Invoice.php'], ['--factory' => true, '--force' => true]],
]);
