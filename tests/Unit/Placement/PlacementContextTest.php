<?php

use Tey\Mod\Exceptions\InvalidPlacementOption;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Tests\Fixtures\Layouts;

it('parses the --in option in declared dimension order', function () {
    $slices = Layouts::verticalSlices();

    expect(PlacementContext::fromOption('', $slices)->isEmpty())->toBeTrue()
        ->and(PlacementContext::fromOption('Billing', $slices)->toArray())->toBe(['feature' => 'Billing'])
        ->and(PlacementContext::fromOption(' Billing / CreateInvoice ', $slices)->toArray())->toBe(['feature' => 'Billing', 'slice' => 'CreateInvoice']);
});

it('rejects too many, malformed and dimensionless placements', function () {
    expect(fn () => PlacementContext::fromOption('Billing/CreateInvoice/Extra', Layouts::verticalSlices()))
        ->toThrow(InvalidPlacementOption::class, 'at most 2 (feature/slice)');

    expect(fn () => PlacementContext::fromOption('Billing/Create-Invoice', Layouts::verticalSlices()))
        ->toThrow(InvalidPlacementOption::class, 'invalid value [Create-Invoice]');

    expect(fn () => PlacementContext::fromOption('Billing/', Layouts::verticalSlices()))
        ->toThrow(InvalidPlacementOption::class, 'invalid value []');

    expect(fn () => PlacementContext::fromOption('Billing', Layouts::ordinary()))
        ->toThrow(InvalidPlacementOption::class, 'This layout takes no placement');
});

it('is an immutable value object', function () {
    $context = PlacementContext::of(['feature' => 'Billing', 'slice' => 'CreateInvoice']);

    expect($context->only(['feature'])->toArray())->toBe(['feature' => 'Billing'])
        ->and($context->without('slice')->equals(PlacementContext::of(['feature' => 'Billing'])))->toBeTrue()
        ->and($context->with('slice', 'CancelInvoice')->get('slice'))->toBe('CancelInvoice')
        ->and($context->get('slice'))->toBe('CreateInvoice')
        ->and($context->has('module'))->toBeFalse()
        ->and($context->names())->toBe(['feature', 'slice'])
        ->and($context->describe())->toBe('feature:Billing, slice:CreateInvoice')
        ->and(PlacementContext::none()->describe())->toBe('-');
});
