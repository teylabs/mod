<?php

use Tey\Mod\Artifact\ArtifactRequest;
use Tey\Mod\Artifact\ResolvedArtifact;
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
