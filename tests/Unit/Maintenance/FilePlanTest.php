<?php

use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\FilePlan;

/**
 * @param  array<string, string>  $files
 * @param  list<string>  $groups
 */
function maintenanceArtifact(string $version = 'A', array $files = ['app/Policy.php' => 'original', 'app/Other.php' => 'other'], array $groups = ['core']): Artifact
{
    return new Artifact('example/blueprint', $version, $files, $groups, ['auth' => 'session'], ['protocol']);
}

it('recognises an untouched original baseline when the current recipe differs', function () {
    $a = maintenanceArtifact();
    $b = maintenanceArtifact('B', ['app/Policy.php' => 'upstream', 'app/Other.php' => 'other']);
    $plan = FilePlan::between('one', '/owned/one', $a, $b, $a->files, ['lock' => 'one'], ['framework' => 'passed']);
    expect($plan->executable())->toBeTrue()
        ->and($plan->files['app/Policy.php'])->toBe(['state' => 'replace', 'original' => hash('sha256', 'original'), 'current' => hash('sha256', 'original'), 'target' => hash('sha256', 'upstream')])
        ->and($plan->files['app/Other.php']['state'])->toBe('unchanged');
});

it('refuses edited and unowned collisions before producing an executable plan', function () {
    $a = maintenanceArtifact();
    $b = maintenanceArtifact('B', ['app/Policy.php' => 'upstream', 'app/Other.php' => 'other', 'app/New.php' => 'new']);
    $current = $a->files;
    $current['app/Policy.php'] = 'custom';
    $current['app/New.php'] = 'host';
    $plan = FilePlan::between('one', '/owned/one', $a, $b, $current, [], ['framework' => 'passed']);
    expect($plan->executable())->toBeFalse()
        ->and($plan->files['app/Policy.php']['state'])->toBe('conflict')
        ->and($plan->files['app/New.php']['state'])->toBe('unowned');
});

it('distinguishes an explicitly owned missing file from an added target', function () {
    $a = maintenanceArtifact();
    $b = maintenanceArtifact('B', ['app/Policy.php' => 'upstream', 'app/Other.php' => 'other', 'app/New.php' => 'new']);
    $plan = FilePlan::between('one', '/owned/one', $a, $b, ['app/Other.php' => 'other'], [], ['framework' => 'passed']);
    expect($plan->files['app/Policy.php']['state'])->toBe('restore')
        ->and($plan->files['app/New.php']['state'])->toBe('create');
});

it('binds identity to app root baseline target current inputs and compatibility', function () {
    $a = maintenanceArtifact();
    $b = maintenanceArtifact('B');
    $make = fn ($id = 'one', $root = '/owned/one', $current = null, $inputs = ['lock' => 'one'], $compatibility = ['framework' => 'passed']) => FilePlan::between($id, $root, $a, $b, $current ?? $a->files, $inputs, $compatibility);
    $plan = $make();
    expect($plan->identity())->toBe($make()->identity());
    foreach ([$make('two'), $make(root: '/owned/two'), $make(current: []), $make(inputs: ['lock' => 'two']), $make(compatibility: ['framework' => 'failed'])] as $changed) {
        expect($changed->identity())->not->toBe($plan->identity());
    }
    expect($make(compatibility: [])->executable())->toBeFalse()
        ->and($make(compatibility: ['framework' => 'unavailable'])->executable())->toBeFalse();
});

it('keeps modules bindings checks and recoverable original bytes in the artifact identity', function () {
    $a = maintenanceArtifact();
    expect($a->files['app/Policy.php'])->toBe('original')
        ->and($a->identity())->not->toBe(maintenanceArtifact(groups: ['core', 'optional'])->identity())
        ->and($a->identity())->not->toBe((new Artifact($a->name, $a->version, $a->files, $a->groups, ['auth' => 'other'], $a->checks))->identity())
        ->and($a->identity())->not->toBe((new Artifact($a->name, $a->version, $a->files, $a->groups, $a->bindings, ['browser']))->identity());
});

it('refuses traversal reserved metadata aliases and case or parent collisions', function ($files) {
    expect(fn () => maintenanceArtifact(files: $files))->toThrow(InvalidArgumentException::class);
})->with([
    [['../escape' => 'x']], [['/absolute' => 'x']], [['C:/absolute' => 'x']], [['a\\b' => 'x']],
    [['a//b' => 'x']], [['a/./b' => 'x']], [['.mod-maintenance/manifest.json' => 'x']],
    [['app/A.php' => 'x', 'app/a.php' => 'y']], [['app' => 'x', 'app/A.php' => 'y']],
]);

it('refuses removals changed module selection and unrelated artifacts pending explicit policy', function () {
    $a = maintenanceArtifact();
    foreach ([maintenanceArtifact('B', ['app/Policy.php' => 'original']), maintenanceArtifact('B', groups: ['core', 'optional']), new Artifact('other/blueprint', 'B', $a->files, $a->groups, $a->bindings, $a->checks)] as $b) {
        expect(fn () => FilePlan::between('one', '/owned/one', $a, $b, $a->files, [], ['framework' => 'passed']))->toThrow(InvalidArgumentException::class);
    }
});

it('refuses syntactic root aliases and unsupported drive or network roots', function (string $root) {
    $a = maintenanceArtifact();
    expect(fn () => FilePlan::between('one', $root, $a, maintenanceArtifact('B'), $a->files, [], ['framework' => 'passed']))->toThrow(InvalidArgumentException::class);
})->with(['/owned/app/..', '/owned/app/.', '/owned//app', '//server/share/app', 'C:/owned/app', 'c:\\owned\\app', '/owned/app/../other', '/owned/app/./other', '/owned/app/', '/owned\\app', "/owned/app\n"]);
