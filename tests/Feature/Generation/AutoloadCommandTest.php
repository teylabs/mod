<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Artisan;
use Pest\TestSuite;
use Tey\Mod\Commands\AutoloadCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\InferredAreasAutoloadCommand;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

function autoloadCommandTestCase(): TestCase
{
    $case = TestSuite::getInstance()->test;

    if (! $case instanceof TestCase) {
        throw new LogicException('Autoload command tests need Testbench.');
    }

    return $case;
}

beforeEach(function () {
    putenv('COLUMNS=72');
});

function autoloadFixture(Workspace $workspace): void
{
    $workspace->write('composer.json', json_encode([
        'name' => 'test/app',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

afterEach(function () {
    putenv('COLUMNS');
});

it('adds the missing ddd root and dumps autoloads once in the application', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        autoloadFixture($workspace);
        $composer = Mockery::mock(Composer::class);
        $composer->shouldReceive('setWorkingPath')->once()->with($workspace->root->path)->andReturnSelf();
        $composer->shouldReceive('dumpAutoloads')->once()->andReturn(0);
        app()->instance(Composer::class, $composer);

        $result = $workspace->artisan('mod:autoload')->assertSuccessful();

        expect($result->output)->toBe("\n   INFO  composer.json is missing 1 autoload entry for the ddd layout.  \n\n  \"Domain\\\\\": \"src/Domain/\" .................................... added  \n  composer dump-autoload ........................................ DONE  \n\n")
            ->and(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])
            ->toBe(['App\\' => 'app/', 'Domain\\' => 'src/Domain/']);

        $workspace->artisan('mod:autoload')->assertSuccessful()
            ->expectsOutputToContain('Every root of the ddd layout is autoloaded.');
    });
});

it('recognizes a parent mapping only when its namespace and folder both match', function (string $path, bool $covered) {
    Workspace::run(null, function (Workspace $workspace) use ($path, $covered) {
        Mod::layout('areas')->root('areas', 'App\\Modules\\', $path)->kind('model', in: '{area}/Models');
        config()->set('mod.layout', 'areas');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $before = $workspace->read('composer.json');
        $result = $workspace->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful();

        if ($covered) {
            expect($result->output)->toContain('Every root of the areas layout is autoloaded.')
                ->and($workspace->read('composer.json'))->toBe($before);
        } else {
            expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4']['App\\Modules\\'])->toBe('src/Areas/');
        }
    });
})->with([['app/Modules', true], ['src/Areas', false]]);

it('adds an outside root and generates a model in Areas with the current layout API', function () {
    Workspace::run(null, function (Workspace $workspace) {
        Mod::layout('areas')->root('areas', 'Areas\\', 'src/Areas')->kind('model', in: '{area}/Models');
        config()->set('mod.layout', 'areas');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful();
        $workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();

        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4']['Areas\\'])->toBe('src/Areas/')
            ->and($workspace->files())->toBe(['src/Areas/Knowledge/Models/Document.php'])
            ->and($workspace->read('src/Areas/Knowledge/Models/Document.php'))->toBe("<?php\n\nnamespace Areas\\Knowledge\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Document extends Model\n{\n    //\n}\n");
    });
});

it('refuses conflicting mappings atomically and names both folders', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $workspace->write('composer.json', "{\"autoload\":{\"psr-4\":{\"Domain\\\\\":\"domain/\"}}}\n");
        $before = $workspace->read('composer.json');
        app()->instance(Composer::class, Mockery::mock(Composer::class));

        $workspace->artisan('mod:autoload')->assertFailed()
            ->expectsOutputToContain('Domain\\')
            ->expectsOutputToContain('domain/')
            ->expectsOutputToContain('src/Domain/');
        expect($workspace->read('composer.json'))->toBe($before);
    });
});

it('reports an exact namespace conflict even when a parent mapping would cover it', function () {
    Workspace::run(null, function (Workspace $workspace) {
        Mod::layout('areas')->root('areas', 'App\\Modules\\', 'app/Modules')->kind('model', in: '{area}/Models');
        config()->set('mod.layout', 'areas');
        $workspace->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/","App\\\\Modules\\\\":"elsewhere/"}}}');
        $before = $workspace->read('composer.json');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->artisan('mod:autoload')->assertFailed()->expectsOutputToContain('elsewhere/');
        expect($workspace->read('composer.json'))->toBe($before);
    });
});

it('previews changes without writing or running Composer', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $before = $workspace->read('composer.json');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->artisan('mod:autoload', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('src/Domain/')
            ->expectsOutputToContain('would add');
        expect($workspace->read('composer.json'))->toBe($before);
    });
});

it('preserves indentation, key order, empty objects, arrays and the final newline', function (int $spaces, string $newline) {
    Workspace::run(null, function (Workspace $workspace) use ($spaces, $newline) {
        config()->set('mod.layout', 'ddd');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $data = ['name' => 'test/app', 'extra' => (object) ['empty' => new stdClass, 'list' => []], 'autoload' => (object) ['psr-4' => new stdClass], 'scripts' => []];
        $encode = static fn (array $value): string => preg_replace_callback('/^ +/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4) * $spaces), json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).$newline;
        $workspace->write('composer.json', $encode($data));
        $workspace->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful();
        $data['autoload'] = ['psr-4' => ['Domain\\' => 'src/Domain/', 'App\\Modules\\' => 'app/Modules/', 'Tests\\' => 'tests/']];

        expect($workspace->read('composer.json'))->toBe($encode($data));
    });
})->with([[2, "\n"], [4, "\n"], [2, ''], [4, '']]);

it('adds multiple namespaced roots, normalizes Windows separators and ignores plain file roots', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        Mod::layout('ddd')->root('infrastructure', 'Infrastructure\\', 'src\\Infrastructure')->root('config', null, 'config');
        autoloadFixture($workspace);
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->artisan('mod:autoload', ['--no-dump' => true])->assertSuccessful()
            ->expectsOutputToContain('2 autoload entries');
        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])
            ->toBe(['App\\' => 'app/', 'Domain\\' => 'src/Domain/', 'Infrastructure\\' => 'src/Infrastructure/']);
    });
});

it('recognizes Composer array mappings and exact mappings without a trailing slash', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $workspace->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":["legacy/","app"],"Domain\\\\":["legacy-domain/","src/Domain"]}},"autoload-dev":{"psr-4":{"Tests\\\\":"tests/"}}}');
        $before = $workspace->read('composer.json');
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->artisan('mod:autoload')->assertSuccessful()->expectsOutputToContain('Every root');
        expect($workspace->read('composer.json'))->toBe($before);
    });
});

it('keeps the added entries when Composer is unavailable', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        $composer = Mockery::mock(Composer::class);
        $composer->shouldReceive('setWorkingPath')->once()->with($workspace->root->path)->andReturnSelf();
        $composer->shouldReceive('dumpAutoloads')->once()->andReturn(127);
        app()->instance(Composer::class, $composer);
        $workspace->artisan('mod:autoload')->assertSuccessful()
            ->expectsOutputToContain('Run composer dump-autoload to load the new entries.');
        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])->toHaveKey('Domain\\');
    });
});

it('does not register mod:autoload when mod commands are disabled', function () {
    autoloadCommandTestCase()->bootApplicationUsing(fn ($app) => $app->make('config')->set('mod.commands', false));
    expect(Artisan::all())->not->toHaveKey('mod:autoload');
});

it('asks for an inferred namespace and writes the answer', function () {
    Workspace::run(null, function (Workspace $workspace) {
        app()->bind(AutoloadCommand::class, InferredAreasAutoloadCommand::class);
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        autoloadCommandTestCase()->artisan('mod:autoload', ['--no-dump' => true])
            ->expectsQuestion("src/Areas isn't autoloaded. Which namespace should it use?", 'Company\\Areas\\')
            ->assertSuccessful();
        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])
            ->toBe(['App\\' => 'app/', 'Company\\Areas\\' => 'src/Areas/']);
    });
});

it('uses the inferred default without interaction or the explicit namespace flag', function (?string $namespace) {
    Workspace::run(null, function (Workspace $workspace) use ($namespace) {
        app()->bind(AutoloadCommand::class, InferredAreasAutoloadCommand::class);
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $options = ['--no-dump' => true, ...($namespace === null ? [] : ['--namespace' => $namespace])];
        $result = $workspace->artisan('mod:autoload', $options)->assertSuccessful();
        if ($namespace === null) {
            expect($result->output)->toContain('Using namespace [Areas\\] for [src/Areas]; pass --namespace to choose another.');
        }
        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])
            ->toBe(['App\\' => 'app/', ($namespace === null ? 'Areas\\' : 'Company\\Areas\\') => 'src/Areas/']);
    });
})->with([null, 'Company\\Areas']);

it('rejects invalid composer.json without writing or dumping', function (string $json) {
    Workspace::run(null, function (Workspace $workspace) use ($json) {
        app()->instance(Composer::class, Mockery::mock(Composer::class));
        $workspace->write('composer.json', $json);
        $workspace->artisan('mod:autoload')->assertFailed()->expectsOutputToContain('mod:autoload');
        expect($workspace->read('composer.json'))->toBe($json);
    });
})->with(['{broken', '[]', '{"autoload":[]}', '{"autoload":{"psr-4":[]}}', '{"autoload":{"psr-4":{"App\\\\":false}}}']);

it('reports a failing Composer dump and keeps the entries', function () {
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');
        autoloadFixture($workspace);
        $composer = Mockery::mock(Composer::class);
        $composer->shouldReceive('setWorkingPath')->once()->with($workspace->root->path)->andReturnSelf();
        $composer->shouldReceive('dumpAutoloads')->once()->andReturn(1);
        app()->instance(Composer::class, $composer);
        $workspace->artisan('mod:autoload')->assertFailed()->expectsOutputToContain('composer dump-autoload failed');
        expect(json_decode($workspace->read('composer.json'), true)['autoload']['psr-4'])->toHaveKey('Domain\\');
    });
});
