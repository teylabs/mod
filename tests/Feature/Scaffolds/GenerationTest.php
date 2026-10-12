<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tey\Mod\Commands\JobCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('fills every alias form before sorting imports in a sibling template', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('forms', fn (Scaffold $s) => $s->makes('request', name: 'Store{name}Request', as: 'store')->makes('job'));
        $w->write('stubs/mod.job.stub', "<?php\n\nnamespace {{ namespace }};\n\nuse Zed\\Last;\nuse {{ store.fqcn }};\nuse Alpha\\First;\n\nclass {{ class }} {\n    // {{ store }} {{store.camel}} {{ store.snake }} {{ store.kebab }} {{ store.studly }} {{ store.plural }}\n}\n");
        $w->artisan('mod:forms', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Jobs/Document.php')))->toBe("<?php\n\nnamespace App\\Modules\\Knowledge\\Jobs;\n\nuse Alpha\\First;\nuse App\\Modules\\Knowledge\\Http\\Requests\\StoreDocumentRequest;\nuse Zed\\Last;\n\nclass Document {\n    // StoreDocumentRequest storeDocumentRequest store_document_request store-document-request StoreDocumentRequest StoreDocumentRequests\n}\n");
    });
});

it('uses the app variant before the package variant', function (bool $appVariant) {
    Workspace::run(null, function (Workspace $w) use ($appVariant) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('tasks', fn (Scaffold $s) => $s->makes('job', stub: 'house'));
        $w->write('vendor/acme/stubs/job.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { /* package */ }\n");
        Mod::stubs()->for('job.house', Stub::file($w->root->path('vendor/acme/stubs/job.stub')));
        if ($appVariant) {
            $w->write('stubs/mod.job.house.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { /* app */ }\n");
        }
        $w->artisan('mod:tasks', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain($appVariant ? '/* app */' : '/* package */');
    });
})->with([false, true]);

it('refuses a later member collision before writing the earlier model or its companions', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $w->write(Examples::paths()[7], 'existing controller');
        $before = $w->files();
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe($before)->and($w->read(Examples::paths()[7]))->toBe('existing controller');
    });
});

it('renders the same scaffold bytes into another root despite classes in the app', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('copy', fn (Scaffold $s) => $s->makes('job')->makes('request', name: '{name}Request'));
        $name = 'Copy'.bin2hex(random_bytes(4));
        $w->artisan('mod:copy', ['name' => 'Knowledge:'.$name])->assertSuccessful();
        $expected = [];
        $classes = [];
        foreach ($w->files() as $path) {
            $expected[$path] = $w->read($path);
            $fqcn = 'App\\'.str_replace('/', '\\', substr($path, 4, -4));
            $classes[$fqcn] = $w->root->path($path);
            expect(class_exists($fqcn, false))->toBeFalse();
        }
        $loader = new ClassLoader;
        $loader->addClassMap($classes);
        $loader->register();
        $w->write('render/composer.json', $w->read('composer.json'));
        $renderApp = clone app();
        $renderApp->setBasePath($w->root->path('render'));
        $console = new ConsoleApplication($renderApp, app('events'), 'test');
        $commands = Artisan::all();
        foreach (['mod:copy', 'mod:job', 'mod:request'] as $command) {
            $console->add(clone $commands[$command]);
        }
        $output = new BufferedOutput;
        try {
            $exit = $console->run(new ArrayInput(['command' => 'mod:copy', 'name' => 'Knowledge:'.$name, '--no-interaction' => true]), $output);
            expect($exit)->toBe(0, $output->fetch());
            foreach ($expected as $path => $bytes) {
                expect($w->read('render/'.$path))->toBe($bytes)
                    ->and($w->read($path))->toBe($bytes);
            }
            foreach (array_keys($classes) as $fqcn) {
                expect(class_exists($fqcn, false))->toBeTrue();
            }

            $path = array_key_first($expected);
            $w->write('render/'.$path, '<?php // edited');
            $input = ['command' => 'mod:copy', 'name' => 'Knowledge:'.$name, '--no-interaction' => true];
            expect($console->run(new ArrayInput($input), $output))->toBe(1)
                ->and($output->fetch())->toContain($path.' already exists', 'Nothing was written')
                ->and($w->read('render/'.$path))->toBe('<?php // edited');
            expect($console->run(new ArrayInput([...$input, '--force' => true]), $output))->toBe(0, $output->fetch());
            foreach ($expected as $path => $bytes) {
                expect($w->read('render/'.$path))->toBe($bytes);
            }
        } finally {
            $loader->unregister();
        }
    });
});

it('refuses a class declared elsewhere inside an alternate render root even with force', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('copy', fn (Scaffold $s) => $s->makes('job'));
        $name = 'Inside'.bin2hex(random_bytes(4));
        $fqcn = 'App\\Modules\\Knowledge\\Jobs\\'.$name;
        $path = 'render/legacy/'.$name.'.php';
        $w->write($path, '<?php namespace App\\Modules\\Knowledge\\Jobs; class '.$name.' {}');
        require $w->root->path($path);
        $renderApp = clone app();
        $renderApp->setBasePath($w->root->path('render'));
        $console = new ConsoleApplication($renderApp, app('events'), 'test');
        $commands = Artisan::all();
        foreach (['mod:copy', 'mod:job'] as $command) {
            $console->add(clone $commands[$command]);
        }
        $output = new BufferedOutput;
        $exit = $console->run(new ArrayInput(['command' => 'mod:copy', 'name' => 'Knowledge:'.$name, '--no-interaction' => true, '--force' => true]), $output);
        expect($exit)->toBe(1)
            ->and($output->fetch())->toContain('The class '.$fqcn.' already exists', 'Nothing was written')
            ->and($w->files())->toBe([$path]);
    });
});

it('keeps or overwrites related artifacts as well as explicit members', function (bool $force) {
    Workspace::run(null, function (Workspace $w) use ($force) {
        Examples::setup($w);
        $w->write(Examples::paths()[2], 'existing factory');
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document', $force ? '--force' : '--skip-existing' => true])->assertSuccessful();
        expect($w->read(Examples::paths()[2]))->{$force ? 'toContain' : 'toBe'}($force ? 'class DocumentFactory' : 'existing factory');
    });
})->with([false, true]);

it('reruns a scaffold with an autoloadable test member without loading the test', function (bool $pest) {
    Workspace::run(null, function (Workspace $w) use ($pest) {
        config()->set('mod.layout', 'laravel');
        $name = 'Collision'.bin2hex(random_bytes(4));
        $path = "tests/Feature/{$name}Test.php";
        $job = "app/Jobs/{$name}.php";
        $stub = $pest
            ? "<?php\n\nit('works', function () { expect(true)->toBeTrue(); });\n"
            : "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }} extends \\PHPUnit\\Framework\\TestCase {}\n";
        $w->write('stubs/mod.test.collision.stub', $stub);
        Mod::scaffold('tested', fn (Scaffold $s) => $s->makes('job')->makes('test', name: '{name}Test', stub: 'collision'));
        $w->artisan('mod:tested', ['name' => $name])->assertSuccessful();
        $original = $w->read($path);
        $edited = $original."// edited\n";
        $w->write($path, $edited);
        $w->remove([$job]);

        // Run outside Pest: autoloading the class-less test here triggers Pest's guard.
        $code = <<<'PHP'
require $argv[1];
$case = new class('collision') extends \Tey\Mod\Tests\TestCase {
    public function boot(): void { $this->setUp(); }
};
$case->boot();
app()->setBasePath($argv[2]);
config()->set('mod.layout', 'laravel');
$loader = new \Composer\Autoload\ClassLoader;
$loader->addPsr4('Tests\\', $argv[2].'/tests');
$loader->register();
\Tey\Mod\Facades\Mod::scaffold('tested', fn ($s) => $s->makes('job')->makes('test', name: '{name}Test', stub: 'collision'));
$exit = \Illuminate\Support\Facades\Artisan::call('mod:tested', ['name' => $argv[3], '--no-interaction' => true, ...json_decode($argv[4], true)]);
echo json_encode(['exit' => $exit, 'output' => \Illuminate\Support\Facades\Artisan::output(), 'loaded' => class_exists('Tests\\Feature\\'.$argv[3].'Test', false)], JSON_THROW_ON_ERROR);
PHP;

        foreach ([[], ['--skip-existing' => true], ['--force' => true]] as $options) {
            $process = new Process([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 3).'/vendor/autoload.php', $w->root->path, $name, json_encode($options, JSON_THROW_ON_ERROR)]);
            $process->run();
            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['loaded'])->toBeFalse()
                ->and($result['output'])->not->toContain('Please run');
            if ($options === []) {
                expect($result['exit'])->toBe(1)
                    ->and($result['output'])->toContain($path.' already exists', 'Nothing was written')
                    ->and($w->exists($job))->toBeFalse();
            } else {
                expect($result['exit'])->toBe(0, $result['output'])
                    ->and($w->read($job))->toContain('class '.$name);
            }
            expect($w->read($path))->toBe(isset($options['--force']) ? $original : $edited);
        }
    });
})->with(['Pest' => true, 'PHPUnit' => false]);

it('rejects overlapping planned member paths even with force', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('bad', fn (Scaffold $s) => $s->makes('model', as: 'first')->makes('model', as: 'second'));
        $w->artisan('mod:bad', ['name' => 'Knowledge:Document', '--force' => true])->assertFailed();
        expect($w->files())->toBe([]);
    });
});

it('does not leave the scaffold scope attached to subsequent ordinary commands', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->expectsOutputToContain('already exists');
    });
});

it('lists generated bases without writing them before the plan is accepted', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        isolatedDomainNamespace();
        Mod::scaffold('data-set', fn (Scaffold $s) => $s->makes('dto'));
        Examples::testCase()->artisan('mod:data-set', ['name' => 'Knowledge:Document'])
            ->expectsOutputToContain('src/Domain/Shared/Data/DataTransferObject.php')
            ->expectsConfirmation('Write these 2 files?', 'no')->assertSuccessful();
        expect($w->files())->toBe([]);
    });
});

it('plans recursively generated companions before any member is written', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('resource-set', fn (Scaffold $s) => $s->makes('model', options: ['--resource', '--requests']));
        $w->write('app/Modules/Knowledge/Http/Requests/StoreDocumentRequest.php', 'existing request');
        $w->artisan('mod:resource-set', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe(['app/Modules/Knowledge/Http/Requests/StoreDocumentRequest.php']);
    });
});

it('uses the accepted migration timestamp after the native clock changes', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        app()->bind(ModMigrationCreator::class, fn () => new class(app('files'), $w->root->path('stubs')) extends ModMigrationCreator
        {
            public function datePrefixFor(string $directory): string
            {
                $scope = app(ScaffoldExecution::class);

                return $scope->planning ? '2026_10_08_120000' : '2026_10_08_120100';
            }
        });
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->exists(Examples::paths()[1]))->toBeTrue()
            ->and($w->exists('app/Modules/Knowledge/Database/Migrations/2026_10_08_120100_create_documents_table.php'))->toBeFalse();
    });
});

it('accepts the same explicit placement options as the normal generators', function (array $arguments) {
    Workspace::run(null, function (Workspace $w) use ($arguments) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $w->artisan('mod:pair', $arguments)->assertSuccessful();
        expect($w->files())->toBe(['app/Modules/Knowledge/Jobs/Document.php', 'app/Modules/Knowledge/Models/Document.php']);
    });
})->with([
    [['name' => 'Document', '--module' => 'Knowledge']],
    [['name' => 'Document', '--in' => 'Knowledge']],
]);

it('publishes no variants when the complete plan is cancelled', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        Examples::testCase()->artisan('mod:crud', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation("The crud scaffold uses stubs/mod.controller.crud.stub, which doesn't exist. Create it from the controller stub?", 'yes')
            ->expectsConfirmation('Write these 8 files?', 'no')->assertSuccessful();
        expect($w->exists('stubs/mod.controller.crud.stub'))->toBeFalse()
            ->and($w->exists(Examples::paths()[0]))->toBeFalse();
    });
});

it('reports a new group once after the accepted plan starts writing', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $result = $w->artisan('mod:pair', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(substr_count($result->normalisedOutput(), 'Created new module Knowledge.'))->toBe(1);
    });
});

it('refuses a missing ordinary template before any member is generated', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::stubs()->for('job', Stub::file($w->root->path('missing.stub')));
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $w->artisan('mod:pair', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe([]);
    });
});

class ScaffoldHookJob extends JobCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        file_put_contents($this->getLaravel()->basePath('hook-ran'), (string) $exitCode);
    }
}

it('runs generator write hooks only after the scaffold plan is accepted', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::generators()->use('job', ScaffoldHookJob::class);
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        Examples::testCase()->artisan('mod:pair', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation('Write these 2 files?', 'no')->assertSuccessful();
        expect($w->files())->toBe([]);
    });
});

it('announces each new ddd group once across domain application and test roots per scaffold run', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        Mod::scaffold('trio', fn (Scaffold $s) => $s->makes('model')->makes('request')->makes('test'));
        // Different sibling lists must not turn one logical group into different notices.
        $w->write('src/Domain/Existing/Models/Old.php', '<?php');
        foreach (['Inventory', 'Support'] as $group) {
            $output = $w->artisan('mod:trio', ['name' => $group.':Widget'])->assertSuccessful()->normalisedOutput();
            expect(substr_count($output, 'Created new domain '.$group))->toBe(1)
                ->and($w->exists('src/Domain/'.$group.'/Models/Widget.php'))->toBeTrue()
                ->and($w->exists('app/Modules/'.$group.'/Requests/WidgetRequest.php'))->toBeTrue()
                ->and($w->exists('tests/Feature/'.$group.'/Widget.php'))->toBeTrue();
        }
    });
});
