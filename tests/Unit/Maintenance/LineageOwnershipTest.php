<?php

use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\FilePlan;

/**
 * @param  array<string, string>  $requires
 */
function ownershipArtifact(string $version = 'A', array $requires = []): Artifact
{
    return new Artifact('example/recipe', $version, ['app/One.php' => $version], ['core'], [], ['check'], requires: $requires);
}

/**
 * Pass deliberately invalid requirements past static types.
 *
 * @return array<string, string>
 */
function untypedRequires(mixed $requires): mixed
{
    return $requires;
}

it('keeps 0.8 identities when requires is empty and binds it into identity otherwise', function () {
    $plain = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], [], ['check']);
    expect(ownershipArtifact()->identity())->toBe($plain->identity())
        ->and(ownershipArtifact(requires: ['example/base' => '*'])->identity())->not->toBe($plain->identity())
        ->and(ownershipArtifact(requires: ['example/base' => '*'])->identity())->not->toBe(ownershipArtifact(requires: ['example/base' => '1.0.0'])->identity());
});

it('refuses invalid or self requirements', function (array $requires) {
    expect(fn () => ownershipArtifact(requires: untypedRequires($requires)))->toThrow(InvalidArgumentException::class);
})->with([
    'self' => [['example/recipe' => '*']],
    'empty version' => [['example/base' => '']],
    'unnamed' => [['*']],
]);

it('plans a refused path as a conflict with its reason, whatever is on disk', function (array $current) {
    $plan = FilePlan::between('one', '/app', ownershipArtifact(), ownershipArtifact('B'), $current, [], ['php' => 'passed'], refusals: ['app/One.php' => 'owned_by:example/other']);
    expect($plan->files['app/One.php']['state'])->toBe('conflict')
        ->and($plan->files['app/One.php']['reason'])->toBe('owned_by:example/other')
        ->and($plan->executable())->toBeFalse();
})->with(['owned' => [['app/One.php' => 'A']], 'missing' => [[]]]);

it('omits reasons and keeps plan identity when nothing is refused', function () {
    $plan = FilePlan::between('one', '/app', ownershipArtifact(), ownershipArtifact('B'), ['app/One.php' => 'A'], [], ['php' => 'passed']);
    expect($plan->files['app/One.php'])->not->toHaveKey('reason')
        ->and(FilePlan::between('one', '/app', ownershipArtifact(), ownershipArtifact('B'), ['app/One.php' => 'A'], [], ['php' => 'passed'], refusals: [])->identity())->toBe($plan->identity());
});

it('refuses refusals for paths outside the target', function () {
    expect(fn () => FilePlan::between('one', '/app', ownershipArtifact(), ownershipArtifact('B'), [], [], ['php' => 'passed'], refusals: ['app/Other.php' => 'owned_by:x']))
        ->toThrow(InvalidArgumentException::class);
});

it('binds host-owned prefixes into identity only when declared', function () {
    $plain = ownershipArtifact();
    $declared = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], [], ['check'], hostOwned: ['config/branding.php', 'app/Domain']);
    expect($declared->identity())->not->toBe($plain->identity())
        ->and((new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], ['core'], [], ['check'], hostOwned: []))->identity())->toBe($plain->identity());
});

it('refuses unsafe duplicate or self-covering host-owned prefixes', function (array $hostOwned) {
    expect(fn () => new Artifact('example/recipe', 'A', ['app/Domain/One.php' => 'A'], ['core'], [], ['check'], hostOwned: untypedHostOwned($hostOwned)))->toThrow(InvalidArgumentException::class);
})->with([
    'parent traversal' => [['../outside']],
    'absolute' => [['/etc']],
    'metadata' => [['.mod-maintenance']],
    'duplicate' => [['database', 'database']],
    'own file' => [['app/Domain/One.php']],
    'own parent by case' => [['APP/domain']],
]);

/**
 * Pass deliberately invalid prefixes past static types.
 *
 * @return list<string>
 */
function untypedHostOwned(mixed $hostOwned): mixed
{
    return $hostOwned;
}
