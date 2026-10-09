<?php

use Tey\Mod\Rename\Executor;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Tables\ServiceProvider;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('settles the table offer before the final plan and default-No rename confirmation', function (string $choice, int $count) {
    Workspace::run(null, function (Workspace $w) use ($choice, $count) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        app()->instance(Executor::class, new class($count) implements Executor
        {
            public function __construct(private int $count) {}

            public function execute(Request $request, callable $build, callable $show, callable $confirm): int
            {
                $result = $build($request);
                expect($result->inputs?->request->interactive)->toBeTrue()->and($result->inputs?->request->yes)->toBeFalse();
                expect($result->generated)->toHaveCount($this->count);
                expect($result->plan->wouldWrite)->toBeTrue();
                $show($result);
                expect($confirm())->toBeFalse();

                return 0;
            }

            public function recover(Request $request, callable $show, callable $confirm): int
            {
                throw new LogicException('Unexpected recovery');
            }
        });
        Examples::testCase()->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only'])
            ->expectsConfirmation('Create a reversible rename-table migration from widgets to gadgets?', $choice)
            ->expectsConfirmation('Rename this cluster and stage the changes?', 'no')
            ->expectsOutput('Rename cancelled. Nothing was written.')
            ->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain=v1']))->toBe('');
        expect($w->files())->not->toContain('app/Modules/Inventory/Models/Gadget.php');
    });
})->with([['yes', 1], ['no', 0]]);

it('uses only the explicit flag in non-interactive execution', function (bool $selected, int $count) {
    Workspace::run(null, function (Workspace $w) use ($selected, $count) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        app()->instance(Executor::class, new class($count) implements Executor
        {
            public function __construct(private int $count) {}

            public function execute(Request $request, callable $build, callable $show, callable $confirm): int
            {
                $result = $build($request);
                expect($request->interactive)->toBeFalse()->and($result->generated)->toHaveCount($this->count);
                expect($confirm())->toBeTrue();

                return 0;
            }

            public function recover(Request $request, callable $show, callable $confirm): int
            {
                throw new LogicException('Unexpected recovery');
            }
        });
        $w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--table-migration' => $selected, '--yes' => true])->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain=v1']))->toBe('');
    });
})->with([[true, 1], [false, 0]]);

it('never prompts during an interactive dry run', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        app()->register(ServiceProvider::class);
        $w->write('app/Modules/Inventory/Models/Widget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Widget extends \\Illuminate\\Database\\Eloquent\\Model {}\n");
        S::commit($w);
        Examples::testCase()->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing was written.')
            ->assertSuccessful();
        expect(S::git($w, ['status', '--porcelain=v1']))->toBe('');
    });
});
