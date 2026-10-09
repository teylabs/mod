<?php

use Tey\Mod\Artifact\CompiledFileType;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Relation\RelationResolution;

it('compiles independent shipped layouts without sealing or shared state', function () {
    $definition = Layout::fresh('ddd');
    $first = $definition->compiled();
    $definition->mounts('domain', 'Changed\\', 'changed')->path('changed/{domain}');
    $second = $definition->compiled();
    $context = PlacementContext::of(['domain' => 'Billing/Internal']);

    expect($first->place('model', 'Invoice', $context)->path())->toBe('src/Domain/Billing/Internal/Models/Invoice.php')
        ->and($second->place('model', 'Invoice', $context)->path())->toBe('changed/Billing/Internal/Models/Invoice.php')
        ->and(Layout::fresh('ddd')->compiled()->fileType('model'))->toBeInstanceOf(CompiledFileType::class)
        ->and($first->hasFileType('missing'))->toBeFalse()
        ->and(array_keys($first->fileTypes()))->toContain('model', 'migration');

    expect(Layout::fresh('areas')->extends('modules')->compiled()->hasFileType('model'))->toBeTrue()
        ->and(fn () => Layout::fresh('areas')->extends('missing')->compiled())->toThrow(InvalidLayout::class);
});

it('places and locates public artifacts without exposing identity or naming machinery', function () {
    $layout = Layout::fresh('ddd')->generates('model', nested: true)->compiled();
    $artifact = $layout->place('model', 'Archive/Invoice', PlacementContext::of(['domain' => 'Billing/Internal']));

    expect($artifact->fileType->id)->toBe('model')
        ->and($artifact->fileType->isClass())->toBeTrue()
        ->and($artifact->namespace())->toBe('Domain\\Billing\\Internal\\Models\\Archive')
        ->and($layout->locate((string) $artifact->fqcn())?->path())->toBe($artifact->path())
        ->and($layout->locate('Foreign\\Thing'))->toBeNull()
        ->and($layout->fileType('migration')->isTimestamped())->toBeTrue();

    $file = $layout->place('migration', 'create_invoices_table', PlacementContext::of(['domain' => 'Billing']), ['timestamp' => '2026_01_01_000000']);
    expect($file->namespace())->toBeNull()->and($file->fileType->isClass())->toBeFalse();
});

it('preserves callback identity and public relation endpoints verbatim', function () {
    $primary = ResolvedArtifact::phpClass('custom.model', 'Chosen\\Namespace', 'ExactName', 'chosen\\ExactName.php');
    $target = ResolvedArtifact::phpClass('request', '', 'UpdateInvoice', 'requests/UpdateInvoice.php');
    $relation = Layout::fresh('ddd')->compiled()->relation('controller-update-request');
    $resolution = RelationResolution::resolved($relation, $primary, $target);

    expect($primary->fileType->id)->toBe('custom.model')
        ->and($primary->fqcn())->toBe('Chosen\\Namespace\\ExactName')
        ->and($primary->path())->toBe('chosen/ExactName.php')
        ->and($target->namespace())->toBe('')
        ->and($relation->fromFileType)->toBe('controller')
        ->and($relation->toFileType)->toBe('request')
        ->and($resolution->isResolved())->toBeTrue()
        ->and($resolution->target)->toBe($target)
        ->and(DiscoveryDefinition::forFileType('domain.provider', DiscoveryType::Provider)->fileType)->toBe('domain.provider');
});
