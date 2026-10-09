<?php

use Laravel\Mcp\Request;
use Tey\Mod\Boost\PlanTool;
use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\Request as RenameRequest;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('exposes rename capability and exact schema-valid preview without invoking the executor', function (bool $recover) {
    Workspace::run(null, function (Workspace $w) use ($recover) {
        S::setup($w);
        app()->instance(Executor::class, new class implements Executor
        {
            public function execute(RenameRequest $request, callable $build, callable $show, callable $confirm): int
            {
                throw new LogicException('Preview invoked execution');
            }

            public function recover(RenameRequest $request, callable $show, callable $confirm): int
            {
                throw new LogicException('Preview invoked recovery');
            }
        });
        $before = S::git($w, ['status', '--porcelain=v1']);
        $tool = new PlanTool;
        $data = Scenario::data($tool->handle(new Request(['command' => 'mod:rename', 'arguments' => ['Inventory:Widget', 'Inventory:Gadget', '--scaffold=model-only', '--yes', ...($recover ? ['--recover'] : [])]])), $tool);
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/rename.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(JsonSchema::errors($data, $schema))->toBe([])->and($data['would_write'])->toBe(! $recover)
            ->and(S::git($w, ['status', '--porcelain=v1']))->toBe($before);
        $inventory = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($inventory['rename']['requires_scaffold'])->toBeTrue()->and($inventory['rename']['recipes'][0]['name'])->toBe('model-only');
    });
})->with([false, true]);
