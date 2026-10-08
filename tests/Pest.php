<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\PlacementResolver;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/*
 * Feature tests boot Testbench. Safety net: every owned root must be destroyed
 * by the test that created it. Anything still alive after a test is cleaned up
 * and the test is failed.
 */
require_once __DIR__.'/Expectations.php';

uses(TestCase::class)
    ->afterEach(function () {
        $leaked = OwnedAppRoot::destroyAll();

        expect($leaked)->toBe([], 'Owned app roots were not destroyed by their test.');
    })
    ->in('Feature');

/*
 * Unit tests are pure: no application, no container, no filesystem.
 */
uses(PHPUnit\Framework\TestCase::class)->in('Unit');

/**
 * Resolve one artifact against a preset, placing it with the --in option value.
 *
 * @param  array<string, string|int|float|bool|null>  $attributes
 */
function place(Preset $preset, string $kind, string $name, string $in = '', array $attributes = []): ResolvedArtifact
{
    return (new PlacementResolver($preset))->resolve(
        ArtifactRequest::for($kind, $name, PlacementContext::fromOption($in, $preset), $attributes),
    );
}

const MIGRATION_TIMESTAMP = '2026_01_01_000000';

/**
 * Move the ddd layout's domain root to a namespace no other test uses. Classes
 * stay loaded for the whole PHP process, so a generated base such as
 * Domain\Shared\Data\DataTransferObject may already exist when this test runs;
 * under a fresh namespace it never does. Returns the namespace, without the
 * trailing backslash; the folder stays src/Domain. Pass a namespace to apply
 * the same one again (an acceptance app runs its layout calls more than once).
 */
function isolatedDomainNamespace(?string $namespace = null): string
{
    $namespace ??= 'Domain'.bin2hex(random_bytes(4));

    Mod::layout('ddd')->root('domain', $namespace.'\\', 'src/Domain');

    return $namespace;
}
