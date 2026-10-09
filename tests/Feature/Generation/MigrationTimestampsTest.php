<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\Date;
use Tey\Mod\Generation\CollisionPolicy;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Scaffolds\ScaffoldExecution;
use Tey\Mod\Tests\Feature\Generation\Support\PolicyMigrationCommand;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

it('keeps the native migration clock for repeats and following names', function () {
    Date::setTestNow('2026-01-02 03:04:05');

    try {
        Workspace::run('modules', function (Workspace $w) {
            $command = new PolicyMigrationCommand(app(ModMigrationCreator::class), app(Composer::class));
            $layout = Layouts::modules();
            app(Kernel::class)->registerCommand($command->forKind($layout, $layout->kind('migration')));
            $directory = 'app/Modules/Inventory/Database/Migrations/';
            $first = $directory.'2026_01_02_030405_create_widgets_table.php';
            $second = $directory.'2026_01_02_030406_create_widgets_table.php';
            $third = $directory.'2026_01_02_030407_create_parts_table.php';

            $w->artisan('mod:migration', ['name' => 'Inventory:create_widgets_table'])->assertSuccessful();
            $sentinel = $w->read($first)."\r\n// Keep this migration intact.\r\n";
            $w->write($first, $sentinel);
            $w->artisan('mod:migration', ['name' => 'Inventory:create_widgets_table'])->assertSuccessful();
            $w->artisan('mod:migration', ['name' => 'Inventory:create_parts_table'])->assertSuccessful();

            expect($w->files())->toBe([$first, $second, $third])
                ->and($w->read($first))->toBe($sentinel)
                ->and($w->read($second))->toContain("Schema::create('widgets'")
                ->and($w->read($third))->toContain("Schema::create('parts'");
        });
    } finally {
        Date::setTestNow();
    }
})->skip(! property_exists(MigrationCreator::class, 'currentMigrationPath'), 'This Laravel creator does not allocate collision-aware timestamps.');

it('keeps an accepted migration plan instead of reallocating its timestamp', function (CollisionPolicy $policy) {
    Date::setTestNow('2026-01-02 03:04:05');

    try {
        Workspace::run('modules', function (Workspace $w) use ($policy) {
            $command = new PolicyMigrationCommand(app(ModMigrationCreator::class), app(Composer::class));
            $command->policy = $policy;
            $layout = Layouts::modules();
            app(Kernel::class)->registerCommand($command->forKind($layout, $layout->kind('migration')));
            $execution = new ScaffoldExecution;
            $execution->planning = false;
            $plan = new GenerationPlan(place(Layouts::modules(), 'migration', 'create_widgets_table', 'Inventory', ['timestamp' => '2020_01_02_030405']));
            $execution->plans[$plan->primary->path()] = $plan;
            app()->instance(ScaffoldExecution::class, $execution);

            $w->artisan('mod:migration', ['name' => 'Inventory:create_widgets_table'])->assertSuccessful();

            expect($w->files())->toBe([$plan->primary->path()])
                ->and($w->read($plan->primary->path()))->toContain("Schema::create('widgets'");
        });
    } finally {
        Date::setTestNow();
        app()->forgetInstance(ScaffoldExecution::class);
    }
})->with([CollisionPolicy::Refuse, CollisionPolicy::Native]);
