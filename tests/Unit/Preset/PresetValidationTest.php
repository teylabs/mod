<?php

use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Preset\PresetValidator;
use Tey\Mod\Tests\Fixtures\Layouts;

/**
 * @return list<string>
 */
function issuesFor(callable $mutate, string $layout = 'ordinary'): array
{
    $definition = Layouts::definition($layout);
    $mutate($definition);

    return array_map(fn ($issue) => $issue->code->value, (new PresetValidator)->validate($definition));
}

it('accepts all five layouts', function (string $layout) {
    expect((new PresetValidator)->validate(Layouts::definition($layout)))->toBe([]);
})->with(Layouts::NAMES);

it('reports every issue at once and throws them together', function () {
    $definition = Layouts::definition('ordinary');
    $definition['kinds']['Model'] = ['shape' => 'class', 'root' => 'app', 'segments' => ['Models']];
    $definition['relations']['bogus'] = ['from' => 'model', 'to' => 'repository', 'scope' => 'same', 'mode' => 'generate'];

    $exception = null;

    try {
        CompiledLayout::fromArray($definition);
    } catch (InvalidLayout $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(InvalidLayout::class)
        ->and($exception?->codes())->toBe(['invalid-kind', 'unknown-relation-target'])
        ->and($exception?->getMessage())->toContain(' - relations.bogus: ')->toContain('[unknown-relation-target]');
});

it('detects duplicate kinds', function () {
    expect(issuesFor(function (array &$d) {
        $d['kinds']['model-alias'] = ['id' => 'model', 'shape' => 'class', 'root' => 'app', 'segments' => ['Entities']];
    }))->toBe(['duplicate-kind']);
});

it('detects duplicate command names', function () {
    expect(issuesFor(function (array &$d) {
        $d['kinds']['query']['command'] = 'mod:model';
    }))->toBe(['duplicate-command-name']);
});

it('detects unknown relation targets', function () {
    expect(issuesFor(function (array &$d) {
        $d['relations']['factory']['to'] = 'repository';
    }))->toBe(['unknown-relation-target']);
});

it('detects unknown roots and dimensions', function () {
    expect(issuesFor(function (array &$d) {
        $d['kinds']['model']['root'] = 'src';
    }))->toBe(['unknown-root']);

    expect(issuesFor(function (array &$d) {
        $d['kinds']['model']['segments'] = ['Models', '{module}'];
    }, 'feature-first'))->toBe(['unknown-dimension']);

    expect(issuesFor(function (array &$d) {
        $d['relations']['factory']['scope'] = ['keep' => ['module']];
    }, 'feature-first'))->toBe(['unknown-dimension']);
});

it('detects invalid roots', function (array $root) {
    expect(issuesFor(function (array &$d) use ($root) {
        $d['roots']['bad'] = $root;
    }))->toBe(['invalid-root']);
})->with([
    'absolute path' => [['namespace' => 'Src\\', 'path' => '/srv/app']],
    'escaping path' => [['namespace' => 'Src\\', 'path' => 'app/../../etc']],
    'namespace without trailing separator' => [['namespace' => 'Src', 'path' => 'src']],
    'namespace with invalid segment' => [['namespace' => '9Src\\', 'path' => 'src']],
    'missing path' => [['namespace' => 'Src\\']],
]);

it('detects duplicate and overlapping roots by prefix or path', function () {
    expect(issuesFor(function (array &$d) {
        $d['roots']['again'] = ['namespace' => 'App\\', 'path' => 'src/app'];
    }))->toBe(['duplicate-root']);

    expect(issuesFor(function (array &$d) {
        $d['roots']['again'] = ['namespace' => 'Src\\', 'path' => 'app'];
    }))->toBe(['duplicate-root']);
});

it('allows nested roots because every rule names its root explicitly', function () {
    expect(issuesFor(function (array &$d) {
        $d['roots']['modules'] = ['namespace' => 'App\\Modules\\', 'path' => 'app/Modules'];
    }))->toBe([]);
});

it('detects statically identical placement patterns without a priority', function () {
    expect(issuesFor(function (array &$d) {
        $d['kinds']['report'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Queries']];
    }))->toBe(['duplicate-placement-pattern']);

    expect(issuesFor(function (array &$d) {
        $d['kinds']['report'] = ['shape' => 'class', 'name' => 'as-given', 'root' => 'app', 'segments' => ['Queries'], 'priority' => 1];
    }))->toBe([]);
});

it('detects malformed kinds, relations and the commands flag', function () {
    expect(issuesFor(fn (array &$d) => $d['kinds']['model']['shape'] = 'trait'))->toBe(['invalid-kind']);
    expect(issuesFor(fn (array &$d) => $d['kinds']['model']['name'] = ['prefix' => 'X']))->toBe(['invalid-kind']);
    expect(issuesFor(fn (array &$d) => $d['kinds']['migration']['shape'] = 'class'))->toBe(['invalid-kind']);
    expect(issuesFor(fn (array &$d) => $d['kinds']['model']['place'] = 'not a closure'))->toBe(['invalid-kind']);
    expect(issuesFor(fn (array &$d) => $d['relations']['factory']['mode'] = 'maybe'))->toBe(['invalid-relation']);
    expect(issuesFor(fn (array &$d) => $d['relations']['factory']['scope'] = 'everything'))->toBe(['invalid-relation']);
    expect(issuesFor(fn (array &$d) => $d['relations']['factory']['name'] = ['prefix' => '']))->toBe(['invalid-relation']);
    expect(issuesFor(fn (array &$d) => $d['dimensions'] = ['Feature']))->toBe(['invalid-dimension']);
    expect(issuesFor(fn (array &$d) => $d['dimensions'] = ['feature', 'feature']))->toBe(['invalid-dimension']);
    expect(issuesFor(fn (array &$d) => $d['commands'] = 'yes'))->toBe(['invalid-shape']);
});

it('carries the commands flag for the host', function () {
    expect(Layouts::ordinary()->commandsEnabled())->toBeTrue();

    $definition = Layouts::definition('ordinary');
    $definition['commands'] = false;

    expect(CompiledLayout::fromArray($definition)->commandsEnabled())->toBeFalse();
});

it('exposes kinds, rules and relations by id', function () {
    $preset = Layouts::ordinary();

    expect($preset->hasKind('model'))->toBeTrue()
        ->and($preset->hasKind('repository'))->toBeFalse()
        ->and($preset->kind('controller')->command)->toBe('mod:controller')
        ->and($preset->rule('factory')->root()->path)->toBe('database/factories')
        ->and(array_keys($preset->relations()))->toBe(['factory', 'model', 'policy', 'controller-store-request', 'controller-update-request'])
        ->and(array_map(fn ($r) => $r->id, $preset->relationsFrom('controller')))->toBe(['controller-store-request', 'controller-update-request'])
        ->and(count($preset->roots()))->toBe(4)
        ->and($preset->dimensions())->toBe([]);
});
