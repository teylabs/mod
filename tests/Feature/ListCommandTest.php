<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

beforeEach(function () {
    putenv('COLUMNS=72');
});

it('pins the JSON contract and includes package override members', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->extends('modules');
        app(ScaffoldRegistry::class)->register('crud', fn (Scaffold $s) => $s->makes('model'), 'acme/kit');
        Mod::scaffold('crud', fn (Scaffold $s) => $s->makes('model', options: ['--factory']));
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/../Fixtures/list-schema.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($schema as $section => $keys) {
            $value = match ($section) {
                'root' => $data,
                'type' => $data['types'][0],
                'scaffold' => $data['scaffolds']['items'][0],
                default => $data[$section],
            };
            expect(array_values(array_intersect(array_keys($value), $keys)))->toBe($keys);
        }
        expect($data['layout'])->toBe('areas')->and($data['extends'])->toBe('modules')
            ->and($data['path'])->toBe('app/Modules/{area}')->and($data['token'])->toBe('area')
            ->and($data['discovery']['enabled'])->toBeFalse()
            ->and($data['scaffolds']['items'][0]['source'])->toBe('app (overrides acme/kit)')
            ->and($data['scaffolds']['items'][0]['members'][0])->toBe([
                'alias' => 'model', 'type' => 'model', 'name' => null, 'stub' => null, 'options' => ['--factory'], 'folder' => 'app/Modules/{area}/Models',
            ]);
        expect($w->files())->toBe([]);
    });
});

it('lists every built-in layout and areas with exact console snapshots', function (string $name) {
    Workspace::run(null, function (Workspace $w) use ($name) {
        config()->set('mod.layout', $name);
        if ($name === 'areas') {
            Mod::layout('areas')->extends('modules');
        }
        $result = $w->artisan('mod:list')->assertSuccessful();
        expect($result->normalisedOutput())->toBe(str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/list/'.$name.'.txt')));
    });
})->with(['laravel', 'modules', 'ddd', 'features', 'slices', 'type-first', 'areas']);

it('filters one file type and exposes aliases only with detail', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->generates('tool', in: '@module/Tools', aliases: ['mod:tools']);
        $normal = $w->artisan('mod:list')->assertSuccessful()->normalisedOutput();
        expect($normal)->not->toContain('mod:tools');
        $detail = $w->artisan('mod:list', ['--type' => 'tool'])->assertSuccessful()->normalisedOutput();
        expect($detail)->toContain('mod:tools', 'app/Modules/{module}/Tools');
        expect($detail)->not->toContain('mod:model');
        $w->artisan('mod:list', ['--type' => 'missing'])->assertFailed()->expectsOutputToContain('mod:list --type=missing');
    });
});

it('follows the command switch', function () {
    Workspace::run(null, function () {
        config()->set('mod.commands', false);
        expect(Artisan::all())->not->toHaveKey('mod:list');
    });
});

it('reports an invalid layout with exit one', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'areas');
        Mod::layout('areas')->generates('tool', in: 'Tools')->extends('modules');
        $w->artisan('mod:list')->assertFailed()->expectsOutputToContain("extends('modules') must come first");
    });
});

it('keeps a typical modules inventory readable within seventy-two columns', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $output = $w->artisan('mod:list')->assertSuccessful()->normalisedOutput();
        foreach (explode("\n", $output) as $line) {
            expect(mb_strlen(rtrim($line)))->toBeLessThanOrEqual(72);
        }
        expect(count(explode("\n", $output)))->toBeLessThanOrEqual(42);
    });
});
