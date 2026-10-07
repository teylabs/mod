<?php

use Tey\Mod\Tests\Support\OwnedAppRoot;
use Tey\Mod\Tests\TestCase;

/*
 * Safety net: every owned root must be destroyed by the test that created it.
 * Anything still alive after a test is cleaned up and the test is failed.
 */
uses(TestCase::class)
    ->afterEach(function () {
        $leaked = OwnedAppRoot::destroyAll();

        expect($leaked)->toBe([], 'Owned app roots were not destroyed by their test.');
    })
    ->in(__DIR__);
