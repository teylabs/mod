<?php

use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Exceptions\InvalidGeneratorSetup;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\Kind;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Relation\RelationPolicy;

/**
 * A DDD-like layout, as one chain.
 */
function dddLayout(LayoutRegistry $registry): Layout
{
    return $registry->layout('domains')
        ->root('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r
            ->kind('model', in: '{domain}/Models')
            ->kind('action', in: '{domain}/Actions'))
        ->root('app', 'App\\', 'app', fn (Root $r) => $r
            ->kind('controller', in: 'Modules/{domain}/Controllers', suffix: 'Controller'))
        ->kind('factory', in: 'domain:{domain}/Database/Factories', suffix: 'Factory')
        ->relation('factory', from: 'model', to: 'factory')
        ->exclude('App\\Support\\');
}

function invalidLayout(Closure $define): InvalidLayout
{
    $layout = new Layout('broken');
    $define($layout);

    try {
        $layout->compile();
    } catch (InvalidLayout $exception) {
        return $exception;
    }

    throw new RuntimeException('The layout compiled.');
}

it('returns the layout builder from every chain method', function () {
    $layout = new Layout('chain');

    expect($layout->root('app', 'App\\', 'app'))->toBe($layout)
        ->and($layout->root('lib', 'Lib\\', 'lib', fn (Root $r) => $r->kind('thing', in: 'Things')))->toBe($layout)
        ->and($layout->kind('model', in: 'Models'))->toBe($layout)
        ->and($layout->kind('job', in: 'Jobs', using: fn (Kind $k) => $k->priority(2)))->toBe($layout)
        ->and($layout->relation('jobs', from: 'model', to: 'job'))->toBe($layout)
        ->and($layout->exclude('App\\Support\\'))->toBe($layout)
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
        ->and($preset->relation('factory')->policy)->toBe(RelationPolicy::Generate)
        ->and($preset->excludedRoots()[0]->namespace)->toBe('App\\Support\\')
        ->and($preset->excludedRoots()[0]->path)->toBe('app/Support');
});

it('places top-level kinds in the first declared root unless in: names one', function () {
    $preset = (new Layout('roots'))
        ->root('app', 'App\\', 'app')
        ->root('lib', 'Lib\\', 'lib')
        ->kind('model', in: 'Models')
        ->kind('helper', in: 'lib:Helpers')
        ->kind('base', in: 'lib:')
        ->compile();

    expect(place($preset, 'model', 'Invoice')->fqcn())->toBe('App\\Models\\Invoice')
        ->and(place($preset, 'helper', 'Money')->fqcn())->toBe('Lib\\Helpers\\Money')
        ->and(place($preset, 'base', 'Kernel')->fqcn())->toBe('Lib\\Kernel');
});

it('infers dimensions from placeholders in order of first appearance', function () {
    $preset = (new Layout('order'))
        ->root('app', 'App\\', 'app')
        ->kind('model', in: '{team}/Models')
        ->kind('handler', in: '{team}/{useCase}/{version?}', fixed: 'Handler')
        ->kind('report', in: 'Reports/{version?}/{team}')
        ->compile();

    expect($preset->dimensionNames())->toBe(['team', 'useCase', 'version'])
        ->and(place($preset, 'handler', 'Handler', 'Billing/Pay/V2')->path())->toBe('app/Billing/Pay/V2/Handler.php');
});

it('derives the shape, name policy and command of a kind', function () {
    $preset = (new Layout('shapes'))
        ->root('app', 'App\\', 'app')
        ->root('migrations', null, 'database/migrations')
        ->kind('model', in: 'Models')
        ->kind('controller', in: 'Controllers', suffix: 'Controller', command: 'mod:ctrl')
        ->kind('migration', in: 'migrations:', timestamped: true)
        ->kind('routes', in: 'routes', using: fn (Kind $k) => $k->file())
        ->kind('internal', in: 'Internal', command: false)
        ->compile();

    expect($preset->kind('model')->shape)->toBe(IdentityShape::PhpClass)
        ->and($preset->kind('model')->command)->toBe('mod:model')
        ->and($preset->kind('controller')->command)->toBe('mod:ctrl')
        ->and($preset->kind('controller')->namePolicy->describe())->toBe((new Layout('x'))->root('a', 'A\\', 'a')->kind('c', in: '', suffix: 'Controller')->compile()->kind('c')->namePolicy->describe())
        ->and($preset->kind('migration')->shape)->toBe(IdentityShape::File)
        ->and($preset->kind('routes')->shape)->toBe(IdentityShape::File)
        ->and($preset->kind('internal')->command)->toBeNull()
        ->and($preset->commandsEnabled())->toBeTrue();
});

it('maps relation arguments onto the core relation', function () {
    $preset = (new Layout('relations'))
        ->root('app', 'App\\', 'app')
        ->kind('handler', in: '{team}/{useCase}', fixed: 'Handler')
        ->kind('request', in: '{team}/{useCase}', fixed: 'Request')
        ->kind('model', in: '{team}/Models')
        ->relation('request', from: 'handler', to: 'request')
        ->relation('model', from: 'request', to: 'model', scope: ['team'], name: 'explicit', policy: RelationPolicy::Reference)
        ->relation('store', from: 'handler', to: 'request', name: ['prefix' => 'Store'], policy: 'none')
        ->compile();

    expect($preset->relation('request')->policy)->toBe(RelationPolicy::Generate)
        ->and($preset->relation('model')->policy)->toBe(RelationPolicy::Reference)
        ->and($preset->relation('model')->scope->apply(PlacementContext::of(['team' => 'Billing', 'useCase' => 'Pay']))->toArray())->toBe(['team' => 'Billing'])
        ->and($preset->relation('store')->policy)->toBe(RelationPolicy::None);
});

it('resolves exclusions given as namespaces or paths against the declared roots', function () {
    $preset = (new Layout('excluded'))
        ->root('app', 'App\\', 'app')
        ->root('lib', 'Lib\\Core\\', 'lib/core')
        ->kind('model', in: 'Models')
        ->exclude('App\\Support\\', 'app/UI', 'Lib\\Core\\Internal', 'storage/tmp')
        ->compile();

    expect(array_map(fn ($root) => [$root->namespace, $root->path], $preset->excludedRoots()))->toBe([
        ['App\\Support\\', 'app/Support'],
        ['App\\UI\\', 'app/UI'],
        ['Lib\\Core\\Internal\\', 'lib/core/Internal'],
        [null, 'storage/tmp'],
    ]);
});

it('disables mod:* commands for a host layout', function () {
    expect((new Layout('host'))->root('app', 'App\\', 'app')->kind('model', in: 'Models')->withoutCommands()->compile()->commandsEnabled())->toBeFalse();
});

it('extends a layout: repeated ids override the given arguments and keep the rest', function () {
    $registry = new LayoutRegistry;
    dddLayout($registry);

    $returned = $registry->layout('domains')
        ->kind('model', suffix: 'Model')
        ->kind('controller', in: 'Http/{domain}/Controllers')
        ->kind('query', in: '{domain}/Queries')
        ->root('app', 'App\\', 'application')
        ->relation('factory', policy: 'reference')
        ->exclude('App\\Support\\', 'App\\UI\\');

    $preset = $registry->compile('domains');

    expect($returned)->toBe($registry->layout('domains'))
        ->and(array_keys($preset->kinds()))->toBe(['model', 'action', 'controller', 'factory', 'query'])
        ->and(place($preset, 'model', 'Invoice', 'Billing')->fqcn())->toBe('Domain\\Billing\\Models\\InvoiceModel')
        ->and(place($preset, 'controller', 'Invoice', 'Billing')->path())->toBe('application/Http/Billing/Controllers/InvoiceController.php')
        ->and(place($preset, 'query', 'Overdue', 'Billing')->fqcn())->toBe('Domain\\Billing\\Queries\\Overdue')
        ->and($preset->relation('factory')->fromKind)->toBe('model')
        ->and($preset->relation('factory')->policy)->toBe(RelationPolicy::Reference)
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
        ->toThrow(InvalidGeneratorSetup::class, "Layout [nope] is not defined. Use a built-in layout (laravel, features, slices, type-first, modules, ddd) or define it with Mod::layout('nope')");
});

it('refuses changes to a layout already compiled for use', function () {
    $registry = new LayoutRegistry;
    $registry->compile('laravel');

    expect(fn () => $registry->layout('laravel')->kind('job', in: 'Jobs'))
        ->toThrow(ModException::class, 'Layout [laravel] is already in use');
});

it('names the layout and the offending call in validation errors', function () {
    $exception = invalidLayout(fn (Layout $layout) => $layout
        ->root('domain', 'Domain\\', 'src/Domain', fn (Root $r) => $r->kind('model', in: '{domain}/Models'))
        ->kind('factory', in: 'domian:{domain}/Factories', suffix: 'Factory')
        ->relation('factory', from: 'model', to: 'factory'));

    expect($exception->getMessage())->toBe(implode("\n", [
        'Layout [broken] is invalid:',
        " - ->kind('factory'): root [domian] is not declared (declared: domain) [unknown-root]",
    ]))->and($exception->codes())->toBe(['unknown-root']);
});

it('reports every problem of the chain at once', function (Closure $define, string $line, string $code) {
    $exception = invalidLayout($define);

    expect($exception->getMessage())->toContain($line)
        ->and($exception->codes())->toContain($code);
})->with([
    'kind without in:' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model'),
        "->kind('model'): needs in:", 'invalid-kind',
    ],
    'no root' => [
        fn (Layout $l) => $l->kind('model', in: 'Models'),
        "->kind('model'): no root is declared", 'unknown-root',
    ],
    'partial placeholder' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: 'Team{team}/Models'),
        "->kind('model'): placeholder [Team{team}] must be a whole folder", 'invalid-kind',
    ],
    'placeholder typo' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')
            ->kind('model', in: '{domain}/Models')
            ->kind('action', in: '{domain}/Actions')
            ->kind('query', in: '{domian}/Queries'),
        "->kind('query'): placeholder {domian} is used by no other file type; did you mean {domain}?", 'unknown-dimension',
    ],
    'duplicate command' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: 'Models')->kind('entity', in: 'Entities', command: 'mod:model'),
        "->kind('entity'): command [mod:model] is already used by file type [model]", 'duplicate-command-name',
    ],
    'unknown relation target' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: 'Models')->relation('factory', from: 'model', to: 'factory'),
        "->relation('factory'): to file type [factory] is not declared", 'unknown-relation-target',
    ],
    'scope keeps an unknown placeholder' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: '{team}/Models')->kind('policy', in: '{team}/Policies')->relation('policy', from: 'model', to: 'policy', scope: ['taem']),
        "->relation('policy'): scope keeps dimension [taem] which is not declared", 'unknown-dimension',
    ],
    'exclusion outside every root' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: 'Models')->exclude('Vendor\\Thing\\'),
        "->exclude('Vendor\\Thing\\'): lies inside no declared root", 'unknown-root',
    ],
    'invalid root namespace' => [
        fn (Layout $l) => $l->root('app', 'App', 'app')->kind('model', in: 'Models'),
        "->root('app'): namespace must be a PSR-4 prefix", 'invalid-root',
    ],
    'non-camelCase placeholder' => [
        fn (Layout $l) => $l->root('app', 'App\\', 'app')->kind('model', in: '{Team}/Models')->kind('policy', in: '{Team}/Policies'),
        'placeholder {Team}: must be a camelCase identifier', 'invalid-dimension',
    ],
]);

it('reports a relation to a broken kind once, on the kind', function () {
    $exception = invalidLayout(fn (Layout $l) => $l->root('app', 'App\\', 'app')
        ->kind('model', in: 'Models')
        ->kind('factory', in: 'nowhere:Factories')
        ->relation('factory', from: 'model', to: 'factory'));

    expect($exception->issues)->toHaveCount(1)
        ->and($exception->issues[0]->subject)->toBe("->kind('factory')");
});

it('compiles a fresh preset each time', function () {
    $layout = (new Layout('fresh'))->root('app', 'App\\', 'app')->kind('model', in: 'Models');

    expect($layout->compile())->toBeInstanceOf(Preset::class)
        ->and($layout->compile())->not->toBe($layout->compile());
});

it('takes discoverAnywhere as a boolean and refuses false', function () {
    $preset = (new Layout('anywhere'))->root('app', 'App\\', 'app')
        ->kind('provider', in: 'Providers', discoverAnywhere: true, except: ['Tests'])
        ->compile();
    $rule = $preset->rule('provider');
    assert($rule instanceof TemplateRule);

    expect($rule->anywhere())->toBeTrue()
        ->and($rule->except())->toBe(['Tests'])
        ->and(fn () => (new Layout('no'))->root('app', 'App\\', 'app')->kind('provider', in: 'Providers', discoverAnywhere: false))
        ->toThrow(ModException::class, 'discoverAnywhere cannot be false; leave it out to discover the file type in its own folder only');
});

it('lets kinds share a command name when the layout registers no commands', function () {
    $shared = (new Layout('host'))->root('app', 'App\\', 'app')
        ->kind('model', in: 'Models', command: 'host:model')
        ->kind('legacy-model', in: 'Legacy/Models', command: 'host:model')
        ->withoutCommands()
        ->compile();

    expect($shared->kind('model')->command)->toBe('host:model')
        ->and($shared->kind('legacy-model')->command)->toBe('host:model')
        ->and(fn () => (new Layout('mod'))->root('app', 'App\\', 'app')
            ->kind('model', in: 'Models', command: 'host:model')
            ->kind('legacy-model', in: 'Legacy/Models', command: 'host:model')
            ->compile())
        ->toThrow(InvalidLayout::class, 'already used by file type [model]');
});

it('validates dimensions populated from a relation source name', function () {
    $layout = (new LayoutRegistry)->layout('operations')
        ->root('app', 'App\\', 'app')
        ->kind('controller', in: '{area}/Controllers')
        ->kind('request', in: '{area}/{operation}', fixed: 'Request')
        ->relation('request', from: 'controller', to: 'request', scope: ['name' => 'operation']);
    $preset = $layout->compile();
    expect($preset->relation('request')->scope->nameDimension)->toBe('operation');
    $layout->relation('request', scope: ['name' => 'unknown']);
    expect(fn () => $layout->compile())->toThrow(InvalidLayout::class, 'scope name must identify a declared dimension');
});
