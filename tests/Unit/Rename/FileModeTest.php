<?php

use Tey\Mod\Rename\Git\FileMode;

it('compares exact Unix permissions and Windows read-only attributes', function () {
    expect(FileMode::matches(0644, 0644, 'Linux'))->toBeTrue()
        ->and(FileMode::matches(0600, 0644, 'Linux'))->toBeFalse()
        ->and(FileMode::matches(0755, 0644, 'Darwin'))->toBeFalse()
        ->and(FileMode::matches(0666, 0644, 'Windows'))->toBeTrue()
        ->and(FileMode::matches(0444, 0644, 'Windows'))->toBeFalse()
        ->and(FileMode::matches(0444, 0400, 'Windows'))->toBeTrue();
});
