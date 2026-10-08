<?php

use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Relation\RelationPolicy;

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
    foreach (['factory', 'seeder', 'policy', 'controller', 'migration'] as $relation) {
        expect($preset->relation($relation)->policy)->toBe(RelationPolicy::Generate);
    }
})->with(['laravel', 'features', 'slices', 'type-first', 'modules']);

it('gives every built-in layout its own registry copy', function () {
    $first = new LayoutRegistry;
    $first->layout('modules')->kind('report', in: 'Modules/{module}/Reports');
    expect((new LayoutRegistry)->compile('modules')->hasKind('report'))->toBeFalse()
        ->and($first->compile('modules')->hasKind('report'))->toBeTrue();
});
