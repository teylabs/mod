<?php

use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\Request;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('R9 requires explicit recipe selection and refuses execution until the executor is bound', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $data = S::preview($w, ['--scaffold' => null]);
        expect($data['would_write'])->toBeFalse()->and($data['warnings'][0]['message'])->toContain('requires --scaffold');
        app()->offsetUnset(Executor::class);
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--yes' => true])->assertFailed()->expectsOutputToContain('executor is unavailable');
        expect(S::git($w, ['status', '--porcelain=v1']))->toBe('');
    });
});

it('R9 selects a recipe through Laravel Prompts and defaults final confirmation to No', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->instance(Executor::class, new class implements Executor
        {
            public function execute(Request $request, callable $build, callable $show, callable $confirm): int
            {
                $result = $build($request);
                $show($result);
                expect($request->interactive)->toBeTrue()->and($request->yes)->toBeFalse()
                    ->and($result->plan->wouldWrite)->toBeTrue()->and($confirm())->toBeFalse();

                return 0;
            }

            public function recover(Request $request, callable $show, callable $confirm): int
            {
                throw new LogicException('Unexpected recovery');
            }
        });
        Examples::testCase()->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget'])
            ->expectsChoice('Which scaffold describes this cluster?', 'model-only', ['model-only'])
            ->expectsConfirmation('Rename this cluster and stage the changes?', 'no')
            ->expectsOutput('Rename cancelled. Nothing was written.')
            ->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain=v1']))->toBe('');
    });
});
