<?php

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\InvalidPreset;
use Tey\Mod\Generation\GeneratedBase;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\BuiltIn\Starters;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Preset\Preset;

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
    $preset = (new Layout('aliases'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('record', in: 'Records', aliases: ['mod:records', 'mod:entry'])
            ->kind('entry', in: 'Entries', command: false))
        ->compile();

    expect($preset->kind('record')->aliases)->toBe(['mod:records', 'mod:entry'])
        ->and($preset->kind('record')->command)->toBe('mod:record');

    $collision = (new Layout('collide'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('record', in: 'Records', aliases: ['mod:entry'])
            ->kind('entry', in: 'Entries'));

    expect(fn () => $collision->compile())->toThrow(InvalidLayout::class, 'command [mod:entry] is already used by kind [record]');

    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'kinds' => ['record' => ['shape' => 'class', 'root' => 'app', 'segments' => ['Records'], 'aliases' => ['mod:records']]],
    ];

    expect(fn () => Preset::fromArray([...$definition, 'kinds' => ['record' => [...$definition['kinds']['record'], 'aliases' => 'mod:records']]]))
        ->toThrow(InvalidPreset::class, 'aliases must be a list of command names')
        ->and(fn () => Preset::fromArray($definition))->toThrow(InvalidPreset::class, 'aliases need a command to stand for');
});

it('keeps the stub a layout declares on the compiled preset', function () {
    $stub = Stub::file('/record.stub');
    $preset = (new Layout('stubs'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root->kind('record', in: 'Records', stub: $stub))
        ->compile();

    expect($preset->stub('record'))->toBe($stub)
        ->and($preset->stub('model'))->toBeNull();
});

it('declares the ddd kinds with laravel-ddd command names and aliases', function () {
    $preset = (new LayoutRegistry)->compile('ddd');

    expect([$preset->kind('dto')->command, $preset->kind('dto')->aliases])->toBe(['mod:dto', ['mod:data-transfer-object', 'mod:datatransferobject', 'mod:data']])
        ->and([$preset->kind('value-object')->command, $preset->kind('value-object')->aliases])->toBe(['mod:value', ['mod:value-object', 'mod:valueobject']])
        ->and([$preset->kind('view-model')->command, $preset->kind('view-model')->aliases])->toBe(['mod:view-model', ['mod:viewmodel']])
        ->and($preset->kind('action')->command)->toBe('mod:action')
        ->and($preset->placementOptions())->toBe(['domain' => 'domain'])
        ->and($preset->stub('dto'))->not->toBeNull();
});

it('compiles a kind label and refuses an empty one', function () {
    $preset = (new Layout('labels'))
        ->root('app', 'App\\', 'app', fn (Root $root) => $root
            ->kind('record', in: 'Records', label: 'Ledger record')
            ->kind('entry', in: 'Entries'))
        ->compile();

    expect($preset->kind('record')->label)->toBe('Ledger record')
        ->and($preset->kind('entry')->label)->toBeNull()
        ->and((new LayoutRegistry)->compile('ddd')->kind('dto')->label)->toBe('DTO')
        ->and((new LayoutRegistry)->compile('modules')->kind('dto')->label)->toBe('DTO');

    $definition = [
        'roots' => ['app' => ['namespace' => 'App\\', 'path' => 'app']],
        'kinds' => ['record' => ['shape' => 'class', 'root' => 'app', 'segments' => ['Records'], 'label' => ' ']],
    ];

    expect(fn () => Preset::fromArray($definition))->toThrow(InvalidPreset::class, 'label must be a non-empty string');
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
        ->and($registry->starterFor('dto')?->generatedBase()?->inKindRoot)->toBeFalse()
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

    expect([$dto?->in, $dto?->inKindRoot])->toBe(['Shared/Data', true])
        ->and([$viewModel?->in, $viewModel?->inKindRoot])->toBe(['Shared/ViewModels', true])
        ->and($preset->stub('value-object'))->toBeNull()
        ->and(GeneratedBase::named('Record', in: 'Records', stub: 'x')->inKindRoot()->inKindRoot)->toBeTrue();
});

it('declares dto, view-model and value-object in the modules layout', function () {
    $preset = (new LayoutRegistry)->compile('modules');

    expect([$preset->kind('dto')->command, $preset->kind('dto')->aliases, $preset->kind('dto')->label])->toBe(['mod:dto', ['mod:data'], 'DTO'])
        ->and($preset->kind('value-object')->command)->toBe('mod:value')
        ->and($preset->kind('view-model')->command)->toBe('mod:view-model')
        ->and($preset->hasKind('data'))->toBeFalse();
});
