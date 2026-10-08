<?php

use Illuminate\Console\GeneratorCommand;
use Illuminate\Foundation\Console\ComponentMakeCommand;
use Illuminate\Foundation\Console\ViewMakeCommand;
use Illuminate\Support\Facades\Artisan;
use Pest\TestSuite;
use Tey\Mod\Generation\GeneratorAdapter;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Tests\TestCase;

it('covers every native class generator except the documented held commands', function () {
    $case = TestSuite::getInstance()->test;
    if (! $case instanceof TestCase) {
        throw new RuntimeException('Expected the package Testbench case.');
    }
    $case->bootApplicationUsing(fn ($app) => $app->make('config')->set('mod.commands', false));

    // These generators also create presentation files; their adapters are held to v1.
    $held = [
        ComponentMakeCommand::class => 'Component class and template generation is held to v1.',
        ViewMakeCommand::class => 'View template generation is held to v1.',
    ];
    $checked = [];

    foreach (Artisan::all() as $command) {
        $native = $command::class;

        if (! $command instanceof GeneratorCommand || $command instanceof GeneratorAdapter || ! str_starts_with($native, 'Illuminate\\')) {
            continue;
        }

        if (isset($held[$native])) {
            expect($held[$native])->not->toBeEmpty();

            continue;
        }

        expect(array_key_exists($native, GeneratorRegistry::NATIVE))->toBeTrue("Native generator [{$native}] needs an adapter or a documented hold.");
        $adapter = GeneratorRegistry::NATIVE[$native];
        expect(is_subclass_of($adapter, $native))->toBeTrue();
        $checked[$native] = true;
    }

    foreach (GeneratorRegistry::NATIVE as $native => $adapter) {
        if (is_subclass_of($native, GeneratorCommand::class)) {
            expect($checked)->toHaveKey($native);
        }
    }
});
