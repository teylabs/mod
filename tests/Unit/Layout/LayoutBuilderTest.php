<?php

use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Relation\RelationMode;

/**
 * A DDD-like layout, as one chain.
 */
function dddLayout(LayoutRegistry $registry): Layout
{
    return $registry->layout('domains')
        ->mounts('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r
            ->generates('model', in: '{domain}/Models')
            ->generates('action', in: '{domain}/Actions'))
        ->mounts('app', 'App\\', 'app', fn (Root $r) => $r
            ->generates('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
        ->generates('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
        ->relates('model', to: 'factory', as: 'factory')
        ->excludes('App\\Support\\');
}

function invalidLayout(Closure $define): InvalidLayout
{
    $layout = (new Layout('broken'))->path('app');
    $define($layout);

    try {
        $layout->compile();
    } catch (InvalidLayout $exception) {
        return $exception;
    }

    throw new RuntimeException('The layout compiled.');
}

it('returns the layout builder from every chain method', function () {
    $layout = (new Layout('chain'))->path('app');

    expect($layout->mounts('app', 'App\\', 'app'))->toBe($layout)
        ->and($layout->mounts('lib', 'Lib\\', 'lib', fn (Root $r) => $r->generates('thing', in: 'Things')))->toBe($layout)
        ->and($layout->generates('model', in: 'Models'))->toBe($layout)
        ->and($layout->generates('job', in: 'Jobs', using: fn (FileType $k) => $k->priority(2)))->toBe($layout)
        ->and($layout->relates('model', to: 'job', as: 'jobs'))->toBe($layout)
        ->and($layout->excludes('App\\Support\\'))->toBe($layout)
        ->and($layout->withoutCommands())->toBe($layout);
});

it('compiles a DDD-like layout from one chain with nested closures', function () {
    $preset = dddLayout(new LayoutRegistry)->compile();

    expect($preset->dimensionNames())->toBe(['domain'])
        ->and(array_keys($preset->kinds()))->toBe(['model', 'action', 'controller', 'factory'])
        ->and($preset->kind('controller')->command)->toBe('mod:controller')
        ->and(place($preset, 'model', 'Invoice', 'Billing')->fqcn())->toBe('Domain\\Billing\\Models\\Invoice')
        ->and(place($preset, 'action', 'PayInvoice', 'Billing')->path())->toBe('src/Domain/Billing/Actions/PayInvoice.php')
        ->and(place($preset, 'controller', 'Invoice', 'Billing')->fqcn())->toBe('App\\Modules\\Billing\\Controllers\\InvoiceController')
        ->and(place($preset, 'factory', 'Invoice', 'Billing')->fqcn())->toBe('Domain\\Billing\\Database\\Factories\\InvoiceFactory')
        ->and($preset->relation('factory')->mode)->toBe(RelationMode::Generate)
        ->and($preset->excludedRoots()[0]->namespace)->toBe('App\\Support\\')
        ->and($preset->excludedRoots()[0]->path)->toBe('app/Support');
});

it('places top-level kinds in the first declared root unless in: names one', function () {
    $preset = ((new Layout('roots'))->path('app'))
        ->mounts('app', 'App\\', 'app')
        ->mounts('lib', 'Lib\\', 'lib')
        ->generates('model', in: 'Models')
        ->generates('helper', in: 'lib:Helpers')
        ->generates('base', in: 'lib:')
        ->compile();

    expect(place($preset, 'model', 'Invoice')->fqcn())->toBe('App\\Models\\Invoice')
        ->and(place($preset, 'helper', 'Money')->fqcn())->toBe('Lib\\Helpers\\Money')
        ->and(place($preset, 'base', 'Kernel')->fqcn())->toBe('Lib\\Kernel');
});

it('infers dimensions from placeholders in order of first appearance', function () {
    $preset = ((new Layout('order'))->path('app'))
        ->mounts('app', 'App\\', 'app')
        ->generates('model', in: '{team}/Models')
        ->generates('handler', in: '{team}/{useCase}/{version?}', fixed: 'Handler')
        ->generates('report', in: 'Reports/{version?}/{team}')
        ->compile();

    expect($preset->dimensionNames())->toBe(['team', 'useCase', 'version'])
        ->and(place($preset, 'handler', 'Handler', 'Billing/Pay/V2')->path())->toBe('app/Billing/Pay/V2/Handler.php');
});

it('derives the shape, name policy and command of a kind', function () {
    $preset = ((new Layout('shapes'))->path('app'))
        ->mounts('app', 'App\\', 'app')
        ->mounts('migrations', null, 'database/migrations')
        ->generates('model', in: 'Models')
        ->generates('controller', in: 'Controllers', suffix: 'Controller', command: 'mod:ctrl')
        ->generates('migration', in: 'migrations:', timestamped: true)
        ->generates('routes', in: 'routes', using: fn (FileType $k) => $k->file())
        ->generates('internal', in: 'Internal', command: false)
        ->compile();

    expect($preset->kind('model')->shape)->toBe(IdentityShape::PhpClass)
        ->and($preset->kind('model')->command)->toBe('mod:model')
        ->and($preset->kind('controller')->command)->toBe('mod:ctrl')
        ->and($preset->kind('controller')->namePolicy->describe())->toBe(((new Layout('x'))->path('app'))->mounts('a', 'A\\', 'a')->generates('c', in: '', suffix: 'Controller')->compile()->kind('c')->namePolicy->describe())
        ->and($preset->kind('migration')->shape)->toBe(IdentityShape::File)
        ->and($preset->kind('routes')->shape)->toBe(IdentityShape::File)
        ->and($preset->kind('internal')->command)->toBeNull()
        ->and($preset->commandsEnabled())->toBeTrue();
});

it('maps relation arguments onto the core relation', function () {
    $preset = ((new Layout('relations'))->path('app'))
        ->mounts('app', 'App\\', 'app')
        ->generates('handler', in: '{team}/{useCase}', fixed: 'Handler')
        ->generates('request', in: '{team}/{useCase}', fixed: 'Request')
        ->generates('model', in: '{team}/Models')
        ->relates('handler', to: 'request', as: 'request')
        ->relates('request', to: 'model', as: 'model', scope: ['team'], name: 'explicit', mode: RelationMode::Reference)
        ->relates('handler', to: 'request', as: 'store', name: ['prefix' => 'Store'], mode: 'none')
        ->compile();

    expect($preset->relation('request')->mode)->toBe(RelationMode::Generate)
        ->and($preset->relation('model')->mode)->toBe(RelationMode::Reference)
        ->and($preset->relation('model')->scope->apply(PlacementContext::of(['team' => 'Billing', 'useCase' => 'Pay']))->toArray())->toBe(['team' => 'Billing'])
        ->and($preset->relation('store')->mode)->toBe(RelationMode::None);
});

it('resolves exclusions given as namespaces or paths against the declared roots', function () {
    $preset = ((new Layout('excluded'))->path('app'))
        ->mounts('app', 'App\\', 'app')
        ->mounts('lib', 'Lib\\Core\\', 'lib/core')
        ->generates('model', in: 'Models')
        ->excludes('App\\Support\\', 'app/UI', 'Lib\\Core\\Internal', 'storage/tmp')
        ->compile();

    expect(array_map(fn ($root) => [$root->namespace, $root->path], $preset->excludedRoots()))->toBe([
        ['App\\Support\\', 'app/Support'],
        ['App\\UI\\', 'app/UI'],
        ['Lib\\Core\\Internal\\', 'lib/core/Internal'],
        [null, 'storage/tmp'],
    ]);
});

it('disables mod:* commands for a host layout', function () {
    expect(((new Layout('host'))->path('app'))->mounts('app', 'App\\', 'app')->generates('model', in: 'Models')->withoutCommands()->compile()->commandsEnabled())->toBeFalse();
});

it('extends a layout: repeated ids override the given arguments and keep the rest', function () {
    $registry = new LayoutRegistry;
    dddLayout($registry);

    $returned = $registry->layout('domains')
        ->generates('model', suffix: 'Model')
        ->generates('controller', in: 'Http/{domain}/Controllers')
        ->generates('query', in: '{domain}/Queries')
        ->mounts('app', 'App\\', 'application')
        ->relates('model', to: 'factory', as: 'factory', mode: 'reference')
        ->excludes('App\\Support\\', 'App\\UI\\');

    $preset = $registry->compile('domains');

    expect($returned)->toBe($registry->layout('domains'))
        ->and(array_keys($preset->kinds()))->toBe(['model', 'action', 'controller', 'factory', 'query'])
        ->and(place($preset, 'model', 'Invoice', 'Billing')->fqcn())->toBe('Domain\\Billing\\Models\\InvoiceModel')
        ->and(place($preset, 'controller', 'Invoice', 'Billing')->path())->toBe('application/Http/Billing/Controllers/InvoiceController.php')
        ->and(place($preset, 'query', 'Overdue', 'Billing')->fqcn())->toBe('Domain\\Billing\\Queries\\Overdue')
        ->and($preset->relation('factory')->fromKind)->toBe('model')
        ->and($preset->relation('factory')->mode)->toBe(RelationMode::Reference)
        ->and(count($preset->excludedRoots()))->toBe(2);
});

it('starts a built-in layout from its definition and an unknown name empty', function () {
    $registry = new LayoutRegistry;

    expect($registry->layout('modules')->toArray()['kinds'])->toHaveKey('model')
        ->and($registry->layout('reporting')->toArray()['kinds'])->toBe([])
        ->and($registry->has('laravel'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse()
        ->and($registry->names())->toBe(['laravel', 'features', 'slices', 'type-first', 'modules', 'ddd', 'reporting']);
});

it('refuses to compile a layout nobody defined', function () {
    expect(fn () => (new LayoutRegistry)->compile('nope'))
        ->toThrow(InvalidLayout::class, "Layout [nope] is not defined. Use a built-in layout (laravel, features, slices, type-first, modules, ddd) or define it with Mod::layout('nope')");
});

it('refuses changes to a layout already compiled for use', function () {
    $registry = new LayoutRegistry;
    $registry->compile('laravel');

    expect(fn () => $registry->layout('laravel')->generates('job', in: 'Jobs'))
        ->toThrow(ModException::class, 'Layout [laravel] is already in use');
});

it('names the layout and the offending call in validation errors', function () {
    $exception = invalidLayout(fn (Layout $layout) => $layout
        ->mounts('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r->generates('model', in: '{domain}/Models'))
        ->generates('factory', in: 'domian:{domain}/Factories', suffix: 'Factory')
        ->relates('model', to: 'factory', as: 'factory'));

    expect($exception->getMessage())->toBe(implode("\n", [
        'Layout [broken] is invalid:',
        " - ->generates('factory'): root [domian] is not declared (declared: domain) [unknown-root]",
    ]))->and($exception->codes())->toBe(['unknown-root']);
});

it('reports every problem of the chain at once', function (Closure $define, string $line, string $code) {
    $exception = invalidLayout($define);

    expect($exception->getMessage())->toContain($line)
        ->and($exception->codes())->toContain($code);
})->with([
    'kind without in:' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model'),
        "->generates('model'): needs in:", 'invalid-kind',
    ],
    'no root' => [
        fn (Layout $l) => $l->generates('model', in: 'Models'),
        "->generates('model'): no root is declared", 'unknown-root',
    ],
    'partial placeholder' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: 'Team{team}/Models'),
        "->generates('model'): placeholder [Team{team}] must be a whole folder", 'invalid-kind',
    ],
    'placeholder typo' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')
            ->generates('model', in: '{domain}/Models')
            ->generates('action', in: '{domain}/Actions')
            ->generates('query', in: '{domian}/Queries'),
        "->generates('query'): placeholder {domian} is used by no other file type; did you mean {domain}?", 'unknown-dimension',
    ],
    'duplicate command' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: 'Models')->generates('entity', in: 'Entities', command: 'mod:model'),
        "->generates('entity'): command [mod:model] is already used by file type [model]", 'duplicate-command-name',
    ],
    'unknown relation target' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: 'Models')->relates('model', to: 'factory', as: 'factory'),
        "->relates('factory'): to file type [factory] is not declared", 'unknown-relation-target',
    ],
    'scope keeps an unknown placeholder' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: '{team}/Models')->generates('policy', in: '{team}/Policies')->relates('model', to: 'policy', as: 'policy', scope: ['taem']),
        "->relates('policy'): scope keeps dimension [taem] which is not declared", 'unknown-dimension',
    ],
    'exclusion outside every root' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: 'Models')->excludes('Vendor\\Thing\\'),
        "->excludes('Vendor\\Thing\\'): lies inside no declared root", 'unknown-root',
    ],
    'invalid root namespace' => [
        fn (Layout $l) => $l->mounts('app', 'App', 'app')->generates('model', in: 'Models'),
        "->mounts('app'): namespace must be a PSR-4 prefix", 'invalid-root',
    ],
    'non-camelCase placeholder' => [
        fn (Layout $l) => $l->mounts('app', 'App\\', 'app')->generates('model', in: '{Team}/Models')->generates('policy', in: '{Team}/Policies'),
        'placeholder {Team}: must be a camelCase identifier', 'invalid-dimension',
    ],
]);

it('reports a relation to a broken kind once, on the kind', function () {
    $exception = invalidLayout(fn (Layout $l) => $l->mounts('app', 'App\\', 'app')
        ->generates('model', in: 'Models')
        ->generates('factory', in: 'nowhere:Factories')
        ->relates('model', to: 'factory', as: 'factory'));

    expect($exception->issues)->toHaveCount(1)
        ->and($exception->issues[0]->subject)->toBe("->generates('factory')");
});

it('compiles a fresh preset each time', function () {
    $layout = ((new Layout('fresh'))->path('app'))->mounts('app', 'App\\', 'app')->generates('model', in: 'Models');

    expect($layout->compile())->toBeInstanceOf(CompiledLayout::class)
        ->and($layout->compile())->not->toBe($layout->compile());
});

it('takes discover: anywhere or folder, with discoverExcept only for anywhere', function () {
    $preset = ((new Layout('anywhere'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('provider', in: 'Providers', discover: 'anywhere', discoverExcept: ['Tests'])
        ->generates('listener', in: 'Listeners', discover: 'folder')
        ->compile();
    $provider = $preset->rule('provider');
    $listener = $preset->rule('listener');
    assert($provider instanceof TemplateRule && $listener instanceof TemplateRule);

    expect($provider->anywhere())->toBeTrue()
        ->and($provider->except())->toBe(['Tests'])
        ->and($listener->anywhere())->toBeFalse()
        ->and(fn () => ((new Layout('no'))->path('app'))->mounts('app', 'App\\', 'app')->generates('provider', in: 'Providers', discover: 'everywhere'))
        ->toThrow(ModException::class, "File type [provider]: discover must be 'folder' or 'anywhere'.")
        ->and(fn () => ((new Layout('no'))->path('app'))->mounts('app', 'App\\', 'app')->generates('provider', in: 'Providers', discoverExcept: ['Tests']))
        ->toThrow(ModException::class, "File type [provider]: discoverExcept needs discover: 'anywhere'.");

    // Back to its own folder.
    $back = ((new Layout('back'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('provider', in: 'Providers', discover: 'anywhere', discoverExcept: ['Tests'])
        ->generates('provider', discover: 'folder')
        ->compile()
        ->rule('provider');
    assert($back instanceof TemplateRule);

    expect($back->anywhere())->toBeFalse();
});

it('lets kinds share a command name when the layout registers no commands', function () {
    $shared = ((new Layout('host'))->path('app'))->mounts('app', 'App\\', 'app')
        ->generates('model', in: 'Models', command: 'host:model')
        ->generates('legacy-model', in: 'Legacy/Models', command: 'host:model')
        ->withoutCommands()
        ->compile();

    expect($shared->kind('model')->command)->toBe('host:model')
        ->and($shared->kind('legacy-model')->command)->toBe('host:model')
        ->and(fn () => ((new Layout('mod'))->path('app'))->mounts('app', 'App\\', 'app')
            ->generates('model', in: 'Models', command: 'host:model')
            ->generates('legacy-model', in: 'Legacy/Models', command: 'host:model')
            ->compile())
        ->toThrow(InvalidLayout::class, 'already used by file type [model]');
});

it('validates dimensions populated from a relation source name', function () {
    $layout = (new LayoutRegistry)->layout('operations')
        ->mounts('app', 'App\\', 'app')
        ->generates('controller', in: '{area}/Controllers')
        ->generates('request', in: '{area}/{operation}', fixed: 'Request')
        ->relates('controller', to: 'request', as: 'request', scope: ['name' => 'operation']);
    $preset = $layout->compile();
    expect($preset->relation('request')->scope->nameDimension)->toBe('operation');
    $layout->relates('controller', to: 'request', as: 'request', scope: ['name' => 'unknown']);
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, 'scope name must identify a declared dimension');
});
