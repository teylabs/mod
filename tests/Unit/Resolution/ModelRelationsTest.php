<?php

use Tey\Mod\Resolution\ModelRelations;
use Tey\Mod\Tests\Fixtures\Layouts;

it('declares a target kind only when the layout relates its model kind to it', function (string $layout, bool $factory, bool $policy) {
    $relations = new ModelRelations(Layouts::named($layout));

    expect($relations->declares('factory'))->toBe($factory)
        ->and($relations->declares('policy'))->toBe($policy)
        ->and($relations->declares('seeder-of-nothing'))->toBeFalse();
})->with([
    'ordinary' => ['ordinary', true, true],
    'vertical-slices' => ['vertical-slices', true, false],
    'modules' => ['modules', true, true],
]);

it('answers nothing for a class that is not an owned model', function () {
    $relations = new ModelRelations(Layouts::named('ordinary'));

    expect($relations->targetOfClass(stdClass::class, 'factory'))->toBeNull()
        ->and($relations->targetOfClass('App\\Models\\DoesNotExist', 'factory'))->toBeNull();
});

it('answers nothing for an artifact that is not of the model kind', function () {
    $preset = Layouts::named('ordinary');

    expect((new ModelRelations($preset))->targetOf(place($preset, 'policy', 'InvoicePolicy'), 'factory'))->toBeNull();
});
