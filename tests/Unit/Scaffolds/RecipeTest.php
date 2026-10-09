<?php

use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;

it('defaults aliases and records member arguments', function () {
    $s = new Scaffold;
    $s->makes('model', options: ['--factory']);
    expect($s->members()['model']->fileType)->toBe('model')->and($s->members()['model']->options)->toBe(['--factory']);
});

it('copies includes at definition time and replaces only inherited aliases', function () {
    $r = new ScaffoldRegistry;
    $r->register('crud', fn (Scaffold $s) => $s->makes('model')->makes('controller'));
    $r->register('api', fn (Scaffold $s) => $s->include('crud')->makes('controller', name: 'Api{name}Controller'));
    $r->register('crud', fn (Scaffold $s) => $s->makes('job'));
    expect(array_keys($r->get('api')->members()))->toBe(['model', 'controller'])
        ->and($r->get('api')->members()['controller']->name)->toBe('Api{name}Controller');
});

it('records duplicate local aliases even after replacing an included member', function () {
    $r = new ScaffoldRegistry;
    $r->register('crud', fn (Scaffold $s) => $s->makes('model'));
    $r->register('api', fn (Scaffold $s) => $s->include('crud')->makes('model')->makes('model'));
    expect($r->get('api')->duplicates())->toBe(['model']);
});

it('prefers app over packages regardless of registration order', function (bool $appFirst) {
    $r = new ScaffoldRegistry;
    $app = fn () => $r->register('crud', fn (Scaffold $s) => $s->makes('model'));
    $package = fn () => $r->register('crud', fn (Scaffold $s) => $s->makes('job'), 'acme/kit');
    $appFirst ? $app() : $package();
    $appFirst ? $package() : $app();
    expect(array_keys($r->get('crud')->members()))->toBe(['model']);
})->with([false, true]);
