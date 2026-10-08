<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Relation\RelationMode;

it('ships the common native kinds in every built-in', function (string $name) {
    $preset = (new LayoutRegistry)->compile($name);
    $common = ['model', 'factory', 'seeder', 'migration', 'controller', 'request', 'policy', 'provider', 'command', 'event', 'listener', 'job', 'job-middleware', 'mail', 'notification', 'resource', 'middleware', 'rule', 'observer', 'cast', 'scope', 'enum', 'exception', 'channel', 'class', 'interface', 'trait', 'test'];
    foreach ($common as $kind) {
        expect($preset->hasKind($kind))->toBeTrue("{$name}: {$kind}");
    }
    expect($preset->hasKind('config'))->toBe(in_array($name, ['laravel', 'type-first'], true))
        ->and($preset->hasKind('routes'))->toBeFalse()
        ->and($preset->hasKind('component'))->toBeFalse()
        ->and($preset->hasKind('view'))->toBeFalse()
        ->and($preset->roots()['tests']->namespace)->toBe('Tests\\')
        ->and($preset->roots()['tests']->path)->toBe('tests');
    foreach (['model-factory', 'model-seeder', 'model-policy', 'model-controller', 'model-migration'] as $relation) {
        expect($preset->relation($relation)->mode)->toBe(RelationMode::Generate);
    }
})->with(['laravel', 'features', 'slices', 'type-first', 'modules']);

it('gives every built-in layout its own registry copy', function () {
    $first = new LayoutRegistry;
    $first->layout('modules')->kind('report', in: 'Modules/{module}/Reports');
    expect((new LayoutRegistry)->compile('modules')->hasKind('report'))->toBeFalse()
        ->and($first->compile('modules')->hasKind('report'))->toBeTrue();
});

it('names every built-in relation <from>-<to>[-qualifier], meaning the same in every layout', function (string $layout, array $extra) {
    $companions = [
        'model-factory' => ['model', 'factory'],
        'model-seeder' => ['model', 'seeder'],
        'model-policy' => ['model', 'policy'],
        'model-controller' => ['model', 'controller'],
        'model-migration' => ['model', 'migration'],
        'factory-model' => ['factory', 'model'],
        'listener-event' => ['listener', 'event'],
        'controller-store-request' => ['controller', 'request'],
        'model-store-request' => ['model', 'request'],
    ];
    $relations = [];

    foreach ((new LayoutRegistry)->compile($layout)->relations() as $relation) {
        $relations[$relation->id] = [$relation->fromKind, $relation->toKind];
    }

    expect($relations)->toEqualCanonicalizing([...$companions, ...$extra]);
})->with([
    'laravel' => ['laravel', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'features' => ['features', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'type-first' => ['type-first', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'modules' => ['modules', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'ddd' => ['ddd', ['controller-update-request' => ['controller', 'request'], 'model-update-request' => ['model', 'request']]],
    'slices' => ['slices', ['handler-request' => ['handler', 'request'], 'request-model' => ['request', 'model']]],
]);
