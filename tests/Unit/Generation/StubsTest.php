<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Starters;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Tests\Support\OwnedAppRoot;

/**
 * Every stub file mod ships.
 *
 * @return list<string>
 */
function shippedStubs(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'stub') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * A detector that answers from fixed lists.
 *
 * @param  list<string>  $packages
 * @param  list<string>  $classes
 */
function fakeDetector(array $packages = [], array $classes = []): PackageDetector
{
    return new class($packages, $classes) implements PackageDetector
    {
        /**
         * @param  list<string>  $packages
         * @param  list<string>  $classes
         */
        public function __construct(private array $packages, private array $classes) {}

        public function isInstalled(string $package): bool
        {
            return in_array($package, $this->packages, true);
        }

        public function classExists(string $class): bool
        {
            return in_array($class, $this->classes, true);
        }
    };
}

it('ships only plain or abstract classes in Laravel style', function () {
    expect(shippedStubs())->not->toBeEmpty();

    foreach (shippedStubs() as $stub) {
        $body = (string) file_get_contents($stub);

        expect($body)->not->toMatch('/\bfinal\b/', $stub)
            ->and($body)->not->toMatch('/\breadonly\b/', $stub)
            ->and($body)->toMatch('/^(abstract )?class \{\{ ?class ?\}\}/m', $stub);
    }
});

it('never makes generated classes depend on mod itself', function () {
    foreach (shippedStubs() as $stub) {
        expect((string) file_get_contents($stub))->not->toContain('Tey\\Mod', $stub);
    }
});

it('chooses a configured base, then the first detected variant, then the generated base, then the plain stub', function () {
    $base = GeneratedBase::named('Record', in: 'Shared/Records', stub: '/stubs/bases/record.stub');
    $stub = Stub::file('/stubs/record.stub')
        ->whenInstalled('vendor/records', base: 'Vendor\\Records\\Record')
        ->whenClass('App\\Support\\Record', stub: '/stubs/record.app.stub')
        ->generatesBase($base);
    $config = static fn (string $key): mixed => null;

    $configured = $stub->choose(fakeDetector(['vendor/records']), $config, '\\App\\Support\\BaseRecord');
    expect([$configured->file, $configured->base, $configured->generatedBase, $configured->message])
        ->toBe(['/stubs/record.stub', 'App\\Support\\BaseRecord', null, 'Using the configured base App\\Support\\BaseRecord.']);

    $package = $stub->choose(fakeDetector(['vendor/records'], ['App\\Support\\Record']), $config, null);
    expect([$package->file, $package->base, $package->generatedBase, $package->message])
        ->toBe(['/stubs/record.stub', 'Vendor\\Records\\Record', null, 'Using vendor/records (installed).']);

    $class = $stub->choose(fakeDetector([], ['App\\Support\\Record']), $config, null);
    expect([$class->file, $class->base, $class->generatedBase, $class->message])
        ->toBe(['/stubs/record.app.stub', null, null, 'Using App\\Support\\Record.']);

    $generated = $stub->choose(fakeDetector(), $config, null);
    expect([$generated->file, $generated->base, $generated->generatedBase, $generated->message])
        ->toBe(['/stubs/record.stub', null, $base, null]);

    $plain = Stub::file('/stubs/plain.stub')->choose(fakeDetector(['vendor/records']), $config, null);
    expect([$plain->file, $plain->base, $plain->generatedBase, $plain->message])->toBe(['/stubs/plain.stub', null, null, null]);
});

it('reads an explicit base from the stub itself after the application config', function () {
    $stub = Stub::file('/stubs/record.stub')->base(class: 'App\\Fallback', config: 'records.base');

    expect($stub->choose(fakeDetector(), static fn (string $key): mixed => $key === 'records.base' ? 'App\\FromConfig' : null, null)->base)->toBe('App\\FromConfig')
        ->and($stub->choose(fakeDetector(), static fn (string $key): mixed => null, null)->base)->toBe('App\\Fallback')
        ->and($stub->choose(fakeDetector(), static fn (string $key): mixed => 'App\\FromConfig', 'App\\FromApplication')->base)->toBe('App\\FromApplication');
});

it('names a generated base in published stub files in kebab-case', function () {
    expect(GeneratedBase::named('DataTransferObject', in: '/Shared/Data/', stub: 'x')->stubName())->toBe('data-transfer-object')
        ->and(GeneratedBase::named('DataTransferObject', in: '/Shared/Data/', stub: 'x')->in)->toBe('Shared/Data');
});

it('keeps the stub registered last for a kind and the provider that registered it', function () {
    $registry = new StubRegistry;
    $first = Stub::file('/first.stub');
    $second = Stub::file('/second.stub');

    $provider = new class(app()) extends ServiceProvider
    {
        public function register(): void {}

        public function registerStub(StubRegistry $registry, Stub $stub): void
        {
            $registry->for('record', $stub);
        }
    };

    $registry->for('record', $first);
    expect($registry->get('record'))->toBe($first)
        ->and($registry->registeredBy('record'))->toBeNull();

    $provider->registerStub($registry, $second);

    expect($registry->get('record'))->toBe($second)
        ->and($registry->registeredBy('record'))->toBe($provider::class)
        ->and($registry->get('other'))->toBeNull()
        ->and($registry->publishedPath('record'))->toBe('stubs/mod.record.stub');
});

it('compiles kind aliases and refuses an alias that is already a command name', function () {
    $preset = ((new Layout('aliases'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('record', in: 'Records', aliases: ['mod:records', 'mod:entry'])
            ->generates('entry', in: 'Entries', command: false))
        ->compile();

    expect($preset->kind('record')->aliases)->toBe(['mod:records', 'mod:entry'])
        ->and($preset->kind('record')->command)->toBe('mod:record');

    $collision = ((new Layout('collide'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('record', in: 'Records', aliases: ['mod:entry'])
            ->generates('entry', in: 'Entries'));

    expect(fn () => $collision->compile())->toThrow(InvalidLayout::class, 'command [mod:entry] is already used by file type [record]');

    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'kinds' => ['record' => ['shape' => 'class', 'root' => 'app', 'segments' => ['Records'], 'aliases' => ['mod:records']]],
    ];

    expect(fn () => CompiledLayout::fromArray([...$definition, 'kinds' => ['record' => [...$definition['kinds']['record'], 'aliases' => 'mod:records']]]))
        ->toThrow(InvalidLayout::class, 'aliases must be a list of command names')
        ->and(fn () => CompiledLayout::fromArray($definition))->toThrow(InvalidLayout::class, 'aliases need a command to stand for');
});

it('keeps the stub a layout declares on the compiled preset', function () {
    $stub = Stub::file('/record.stub');
    $preset = ((new Layout('stubs'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root->generates('record', in: 'Records', stub: $stub))
        ->compile();

    expect($preset->stub('record'))->toBe($stub)
        ->and($preset->stub('model'))->toBeNull();
});

it('declares the ddd kinds with laravel-ddd command names and aliases', function () {
    $preset = (new LayoutRegistry)->compile('ddd');

    expect([$preset->kind('dto')->command, $preset->kind('dto')->aliases])->toBe(['mod:dto', ['mod:data-transfer-object', 'mod:data', 'mod:datatransferobject']])
        ->and([$preset->kind('value-object')->command, $preset->kind('value-object')->aliases])->toBe(['mod:value-object', ['mod:value', 'mod:valueobject']])
        ->and([$preset->kind('view-model')->command, $preset->kind('view-model')->aliases])->toBe(['mod:view-model', ['mod:viewmodel']])
        ->and($preset->kind('action')->command)->toBe('mod:action')
        ->and($preset->placementOptions())->toBe(['domain' => 'domain'])
        ->and($preset->stub('dto'))->not->toBeNull();
});

it('compiles a kind label and refuses an empty one', function () {
    $preset = ((new Layout('labels'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('record', in: 'Records', label: 'Ledger record')
            ->generates('entry', in: 'Entries'))
        ->compile();

    expect($preset->kind('record')->label)->toBe('Ledger record')
        ->and($preset->kind('entry')->label)->toBeNull()
        ->and((new LayoutRegistry)->compile('ddd')->kind('dto')->label)->toBe('DTO')
        ->and((new LayoutRegistry)->compile('modules')->kind('dto')->label)->toBe('DTO');

    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'kinds' => ['record' => ['shape' => 'class', 'root' => 'app', 'segments' => ['Records'], 'label' => ' ']],
    ];

    expect(fn () => CompiledLayout::fromArray($definition))->toThrow(InvalidLayout::class, 'label must be a non-empty string');
});

it('maps each starter to the kind ids it applies to, and no other', function () {
    $registry = Starters::register(new StubRegistry);

    foreach (['dto' => 'DTO', 'data' => 'DTO', 'data-transfer-object' => 'DTO', 'view-model' => 'View model', 'viewmodel' => 'View model', 'value-object' => 'Value object', 'value' => 'Value object', 'action' => 'Action'] as $kind => $label) {
        expect($registry->starterFor($kind)?->labelText())->toBe($label, $kind);
    }

    foreach (['query', 'handler', 'message', 'validator', 'model', 'class'] as $kind) {
        expect($registry->starterFor($kind))->toBeNull($kind);
    }

    expect($registry->starterFor('dto')?->generatedBase()?->name)->toBe('DataTransferObject')
        ->and($registry->starterFor('dto')?->generatedBase()?->in)->toBe('Data')
        ->and($registry->starterFor('dto')?->generatedBase()?->inFileTypeRoot)->toBeFalse()
        ->and($registry->starterFor('view-model')?->generatedBase()?->in)->toBe('ViewModels')
        ->and($registry->starterFor('value-object')?->generatedBase())->toBeNull()
        ->and($registry->starterFor('action')?->generatedBase())->toBeNull();
});

it('resolves a registered stub, then the layout stub, then the starter', function () {
    $registry = Starters::register(new StubRegistry);
    $layout = Stub::file('/layout.stub');
    $package = Stub::file('/package.stub');

    expect($registry->resolve('dto', null))->toBe($registry->starterFor('dto'))
        ->and($registry->resolve('dto', $layout))->toBe($layout)
        ->and($registry->resolve('query', null))->toBeNull();

    $registry->for('dto', $package);

    expect($registry->resolve('dto', $layout))->toBe($package);
});

it('keeps the ddd bases in the domain root, where laravel-ddd puts them', function () {
    $preset = (new LayoutRegistry)->compile('ddd');
    $dto = $preset->stub('dto')?->generatedBase();
    $viewModel = $preset->stub('view-model')?->generatedBase();

    expect([$dto?->in, $dto?->inFileTypeRoot])->toBe(['Shared/Data', true])
        ->and([$viewModel?->in, $viewModel?->inFileTypeRoot])->toBe(['Shared/ViewModels', true])
        ->and($preset->stub('value-object'))->toBeNull()
        ->and(GeneratedBase::named('Record', in: 'Records', stub: 'x')->inFileTypeRoot()->inFileTypeRoot)->toBeTrue();
});

it('declares dto, view-model and value-object in the modules layout', function () {
    $preset = (new LayoutRegistry)->compile('modules');

    expect([$preset->kind('dto')->command, $preset->kind('dto')->aliases, $preset->kind('dto')->label])->toBe(['mod:dto', ['mod:data'], 'DTO'])
        ->and($preset->kind('value-object')->command)->toBe('mod:value-object')
        ->and($preset->kind('view-model')->command)->toBe('mod:view-model')
        ->and($preset->hasKind('data'))->toBeFalse();
});

it('fills stub placeholders with LF line endings on every OS', function () {
    // Generated PHP is LF everywhere; PHP_EOL would write CRLF on Windows.
    // ControllerCommand keeps PHP_EOL where Laravel's own controller generator uses it.
    foreach (['src/Commands/Concerns/PlacesGeneratedClass.php', 'src/Generation/BaseWriter.php', 'src/Commands/GenericClassCommand.php', 'src/Commands/BasesCommand.php'] as $file) {
        $constants = array_filter(
            PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 3).'/'.$file)),
            static fn (PhpToken $token): bool => $token->is(T_STRING) && $token->text === 'PHP_EOL',
        );

        expect($constants)->toBe([], $file);
    }
});

it('gives every hyphenated command and alias a dash-free alias, unless the name is taken', function () {
    $preset = ((new Layout('dashes'))->path('app'))
        ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
            ->generates('api-resource', in: 'Resources')
            ->generates('job-middleware', in: 'Jobs/Middleware')
            ->generates('report-builder', in: 'Reports', aliases: ['mod:report-maker'])
            ->generates('jobmiddleware', in: 'Other'))
        ->compile();

    expect($preset->kind('api-resource')->aliases)->toBe(['mod:apiresource'])
        // mod:jobmiddleware is the command of another file type: the real command wins.
        ->and($preset->kind('job-middleware')->aliases)->toBe([])
        ->and($preset->kind('jobmiddleware')->command)->toBe('mod:jobmiddleware')
        ->and($preset->kind('report-builder')->aliases)->toBe(['mod:report-maker', 'mod:reportbuilder', 'mod:reportmaker'])
        ->and((new LayoutRegistry)->compile('laravel')->kind('job-middleware')->aliases)->toBe(['mod:jobmiddleware'])
        ->and((new LayoutRegistry)->compile('laravel')->kind('model')->aliases)->toBe([]);
});

it('declares the same dto, value-object and view-model commands in modules and ddd', function (string $layout) {
    $preset = (new LayoutRegistry)->compile($layout);

    expect($preset->kind('dto')->command)->toBe('mod:dto')
        ->and($preset->kind('dto')->aliases)->toContain('mod:data')
        ->and([$preset->kind('value-object')->command, $preset->kind('value-object')->aliases])->toBe(['mod:value-object', ['mod:value', 'mod:valueobject']])
        ->and([$preset->kind('view-model')->command, $preset->kind('view-model')->aliases])->toBe(['mod:view-model', ['mod:viewmodel']]);
})->with(['modules', 'ddd']);

it('selects published registered layout starter and native stubs with provenance', function () {
    OwnedAppRoot::using(function ($root) {
        $registry = (new StubRegistry)->starter('record', Stub::file('/starter.stub'));
        $detector = fakeDetector(['vendor/records']);
        $config = static fn (string $key): mixed => null;
        $select = fn (?Stub $stub = null) => $registry->select('record', $stub, $root->path, $detector, $config);
        expect($select()->source)->toBe('starter')->and($select()->file)->toBe('/starter.stub');
        $layout = Stub::file('/layout.stub')->whenInstalled('vendor/records', stub: '/variant.stub');
        expect($select($layout)->source)->toBe('layout (vendor/records)')->and($select($layout)->file)->toBe('/variant.stub');
        $registry->for('record', Stub::file('/registered.stub'));
        expect($select($layout)->source)->toBe('registered')->and($select($layout)->file)->toBe('/registered.stub');
        mkdir($root->path('stubs'));
        file_put_contents($root->path('stubs/mod.record.stub'), '<?php');
        expect($select($layout)->source)->toBe('published stub')->and($select($layout)->file)->toBe($root->path('stubs/mod.record.stub'));
        expect($registry->select('model', null, $root->path, $detector, $config, native: true)->source)->toBe('Laravel')
            ->and($registry->select('query', null, $root->path, $detector, $config)->source)->toBe('empty class');
    });
});

it('shares the native migration stub branch for inspection and creation', function () {
    OwnedAppRoot::using(function ($root) {
        mkdir($root->path('stubs'));
        $creator = new ModMigrationCreator(new Filesystem, $root->path('stubs'));
        expect($creator->stubSelection()->source)->toBe('Laravel');
        foreach (['migration' => [null, false], 'migration.create' => ['records', true], 'migration.update' => ['records', false]] as $stub => [$table, $create]) {
            file_put_contents($root->path('stubs/'.$stub.'.stub'), 'chosen '.$stub);
            $selection = $creator->stubSelection($table, $create);
            expect($selection->file)->toBe($root->path('stubs/'.$stub.'.stub'))->and($selection->source)->toBe('published stub');
            $getStub = new ReflectionMethod($creator, 'getStub');
            expect($getStub->invoke($creator, $table, $create))->toBe('chosen '.$stub);
        }
    });
});
