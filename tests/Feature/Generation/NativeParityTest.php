<?php

use Illuminate\Foundation\Console\ConfigMakeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Assert;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\NativeLayout;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

// Explicit cases ensure every registered adapter has a byte/output/exit comparison.
dataset('native adapters', [
    'cast' => ['cast', 'ExampleCast'],
    'channel' => ['channel', 'ExampleChannel'],
    'class' => ['class', 'ExampleClass'],
    'config' => ['config', 'example'],
    'enum' => ['enum', 'ExampleEnum'],
    'exception' => ['exception', 'ExampleException'],
    'interface' => ['interface', 'ExampleContract'],
    'job' => ['job', 'ExampleJob'],
    'job-middleware' => ['job-middleware', 'ExampleMiddleware'],
    'mail' => ['mail', 'ExampleMail'],
    'middleware' => ['middleware', 'ExampleMiddleware'],
    'notification' => ['notification', 'ExampleNotification'],
    'observer' => ['observer', 'ExampleObserver'],
    'resource' => ['resource', 'ExampleResource'],
    'rule' => ['rule', 'ExampleRule'],
    'scope' => ['scope', 'ExampleScope'],
    'test' => ['test', 'ExampleTest'],
    'trait' => ['trait', 'ExampleTrait'],
    'model' => ['model', 'ExampleModel'],
    'controller' => ['controller', 'ExampleController'],
    'request' => ['request', 'ExampleRequest'],
    'factory' => ['factory', 'ExampleFactory'],
    'policy' => ['policy', 'ExamplePolicy'],
    'provider' => ['provider', 'ExampleServiceProvider'],
    'command' => ['command', 'ExampleCommand'],
    'listener' => ['listener', 'ExampleListener'],
    'event' => ['event', 'ExampleEvent'],
    'seeder' => ['seeder', 'ExampleSeeder'],
    'inbound cast' => ['cast', 'ExampleCast', ['--inbound' => true]],
    'invokable class' => ['class', 'ExampleClass', ['--invokable' => true]],
    'string enum' => ['enum', 'ExampleEnum', ['--string' => true]],
    'integer enum' => ['enum', 'ExampleEnum', ['--int' => true]],
    'synchronous job' => ['job', 'ExampleJob', ['--sync' => true]],
    'batchable job' => ['job', 'ExampleJob', ['--batched' => true]],
    'collection resource' => ['resource', 'ExampleCollection', ['--collection' => true]],
    'implicit rule' => ['rule', 'ExampleRule', ['--implicit' => true]],
    'unit PHPUnit test' => ['test', 'ExampleTest', ['--unit' => true, '--phpunit' => true]],
    'feature Pest test' => ['test', 'ExampleTest', ['--pest' => true]],
    'unit Pest test' => ['test', 'ExampleTest', ['--unit' => true, '--pest' => true]],
]);

it('matches native bytes, output, exit codes, duplicate and force behavior', function (string $kind, string $name, array $options = []) {
    if ($kind === 'config' && ! class_exists(ConfigMakeCommand::class)) {
        Assert::markTestSkipped('This framework does not supply make:config.');
    }

    Workspace::run(null, function (Workspace $workspace) use ($kind, $name, $options) {
        NativeLayout::extend();
        mkdir($workspace->root->path('app/Models'));
        $commands = Artisan::all();
        $native = $commands['make:'.$kind];
        $adapter = $commands['mod:'.$kind];
        expect($adapter::class)->toBe(GeneratorRegistry::NATIVE[$native::class]);

        $nativeOptions = array_keys($native->getDefinition()->getOptions());
        $adapterOptions = array_keys($adapter->getDefinition()->getOptions());
        // Plan and placement options: --dry-run/--json, --in, plus one per dimension the kind reads (the fixture places tests by {group?}).
        expect(array_values(array_diff($adapterOptions, $nativeOptions)))->toBe($kind === 'test' ? ['dry-run', 'json', 'in', 'group'] : ['dry-run', 'json', 'in'])
            ->and(array_values(array_diff($nativeOptions, $adapterOptions)))->toBe([]);

        foreach ($native->getDefinition()->getOptions() as $option => $definition) {
            $placed = $adapter->getDefinition()->getOption($option);
            expect([$placed->getShortcut(), $placed->getDefault(), $placed->isValueRequired(), $placed->isValueOptional(), $placed->isArray(), $placed->getDescription()])
                ->toBe([$definition->getShortcut(), $definition->getDefault(), $definition->isValueRequired(), $definition->isValueOptional(), $definition->isArray(), $definition->getDescription()]);
        }

        $initial = $workspace->artisan('make:'.$kind, ['name' => $name, ...$options])->assertSuccessful();
        $files = [];

        foreach ($workspace->files() as $path) {
            $files[$path] = $workspace->read($path);
        }

        expect($files)->not->toBeEmpty();
        $duplicate = $workspace->artisan('make:'.$kind, ['name' => $name, ...$options])->assertSuccessful();
        if ($native->getDefinition()->hasOption('force')) {
            foreach ($files as $path => $bytes) {
                $workspace->write($path, $bytes."\n// changed before force\n");
            }
        }

        $forced = $native->getDefinition()->hasOption('force')
            ? $workspace->artisan('make:'.$kind, ['name' => $name, ...$options, '--force' => true])->assertSuccessful()
            : null;

        foreach ($files as $path => $bytes) {
            unlink($workspace->root->path($path));
        }

        $result = $workspace->artisan('mod:'.$kind, ['name' => $name, ...$options])->assertSuccessful();
        expect($result->output)->toBe($initial->output)
            ->and($result->exitCode)->toBe($initial->exitCode)
            ->and($workspace->files())->toBe(array_keys($files));

        foreach ($files as $path => $bytes) {
            expect($workspace->read($path))->toBe($bytes);
        }

        $result = $workspace->artisan('mod:'.$kind, ['name' => $name, ...$options])->assertSuccessful();
        // These native commands consult the autoloader, which does not own this
        // temporary root. Mod retains its file refusal but matches native exit 0.
        if (in_array($kind, ['exception', 'listener'], true)) {
            $result->expectsOutputToContain('.php already exists.');
        } else {
            expect($result->output)->toBe($duplicate->output);
        }

        expect($result->exitCode)->toBe($duplicate->exitCode);

        foreach ($files as $path => $bytes) {
            expect($workspace->read($path))->toBe($bytes);
        }

        if ($forced !== null) {
            foreach ($files as $path => $bytes) {
                $workspace->write($path, $bytes."\n// changed before force\n");
            }

            $result = $workspace->artisan('mod:'.$kind, ['name' => $name, ...$options, '--force' => true])->assertSuccessful();
            expect($result->output)->toBe($forced->output)
                ->and($result->exitCode)->toBe($forced->exitCode);

            foreach ($files as $path => $bytes) {
                expect($workspace->read($path))->toBe($bytes);
            }
        }
    });
})->with('native adapters');

it('matches native migration bytes, output and exit code', function () {
    Workspace::run(null, function (Workspace $workspace) {
        Date::setTestNow('2026-01-01 00:00:00');

        try {
            $native = $workspace->artisan('make:migration', ['name' => 'create_examples_table'])->assertSuccessful();
            $path = $workspace->migration('database/migrations', 'create_examples_table');
            $bytes = $workspace->read($path);
            $nativeDuplicate = $workspace->artisan('make:migration', ['name' => 'create_examples_table'])->assertSuccessful();
            $nativeFiles = $workspace->files();

            foreach ($nativeFiles as $file) {
                unlink($workspace->root->path($file));
            }

            $adapter = $workspace->artisan('mod:migration', ['name' => 'create_examples_table'])->assertSuccessful();

            expect($adapter->output)->toBe($native->output)
                ->and($adapter->exitCode)->toBe($native->exitCode)
                ->and($workspace->read($path))->toBe($bytes);

            $duplicate = $workspace->artisan('mod:migration', ['name' => 'create_examples_table'])->assertSuccessful();
            expect($duplicate->exitCode)->toBe($nativeDuplicate->exitCode)
                ->and($workspace->files())->toBe([$path])
                ->and($workspace->read($path))->toBe($bytes);

            // Mod keeps migration names unique even when Laravel advances the timestamp.
            $duplicate->expectsOutputToContain('.php already exists.');
        } finally {
            Date::setTestNow();
        }
    });
});

it('registers config only when the framework supplies the native generator', function () {
    Workspace::run(null, function () {
        NativeLayout::extend();
        expect(isset(Artisan::all()['mod:config']))->toBe(class_exists(ConfigMakeCommand::class));
    });
});
