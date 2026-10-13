<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Composer;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\PolicyMigrationCommand;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Fixtures\Layouts;

it('matches an existing migration by its name across timestamps', function (string $mode, bool $direct, bool $native) {
    Workspace::run(null, function (Workspace $w) use ($mode, $direct, $native) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('crud', fn (Scaffold $s) => $direct
            ? $s->makes('migration', name: 'create_widgets_table')
            : $s->makes('model', options: ['--migration']));
        if ($native) {
            $command = new PolicyMigrationCommand(app(ModMigrationCreator::class), app(Composer::class));
            $layout = Layouts::modules();
            app(Kernel::class)->registerCommand($command->forKind($layout, $layout->kind('migration')));
        }
        $path = 'app/Modules/Inventory/Database/Migrations/2020_01_02_030405_create_widgets_table.php';
        $original = '<?php // existing migration';
        $w->write($path, $original);
        $w->write('app/Modules/Inventory/Models/Widget.php', '<?php // existing model');
        $w->write('app/Modules/Other/Database/Migrations/2019_01_02_030405_create_widgets_table.php', '<?php // other group');
        $w->write('app/Modules/Inventory/Database/Migrations/2020_01_02_030406_create_widgets_table_extra.php', '<?php // different name');
        $before = $w->files();
        $result = $w->artisan('mod:crud', [
            'name' => 'Inventory:Widget',
            ...($mode === 'refuse' ? [] : [$mode === 'skip' ? '--skip-existing' : '--force' => true]),
        ]);
        if ($mode === 'refuse') {
            $result->assertFailed()->expectsOutputToContain($path);
            expect($w->files())->toBe($before)->and($w->read($path))->toBe($original);
        } else {
            $result->assertSuccessful();
            expect($w->read($path))->{$mode === 'force' ? 'toContain' : 'toBe'}($mode === 'force' ? "Schema::create('widgets'" : $original);
            expect(array_values(array_filter($w->files(), fn (string $file) => str_ends_with($file, '_create_widgets_table.php'))))
                ->toBe([$path, 'app/Modules/Other/Database/Migrations/2019_01_02_030405_create_widgets_table.php']);
            if (! $direct) {
                expect($w->read('app/Modules/Inventory/Models/Widget.php'))->{$mode === 'force' ? 'toContain' : 'toBe'}($mode === 'force' ? 'class Widget' : '<?php // existing model');
            }
            if ($mode === 'force') {
                $result->expectsOutputToContain('Migration ['.$path.'] created successfully.');
            }
        }
    });
})->with(['refuse', 'skip', 'force'])->with([false, true])->with([false, true]);

it('previews and skips a root migration at its written timestamp when rerunning a tree', function (bool $direct) {
    Workspace::run(null, function (Workspace $w) use ($direct) {
        config()->set('mod.layout', 'modules');
        $creator = new class(app('files'), $w->root->path('stubs')) extends ModMigrationCreator
        {
            public string $timestamp = '2026_10_09_120000';

            public function datePrefixFor(string $directory): string
            {
                return $this->timestamp;
            }
        };
        app()->instance(ModMigrationCreator::class, $creator);
        Mod::scaffold('migration-tree', function (Scaffold $s) use ($direct) {
            $direct ? $s->makes('migration', name: 'create_widgets_table') : $s->makes('model', options: ['--migration']);
            $s->asks('children', type: 'list', default: ['Child'])
                ->part('child', configure: fn (Part $p) => $p->makes('job', name: '{name}{child}'))
                ->each('children', part: 'child');
        });
        $w->artisan('mod:migration-tree', ['name' => 'Inventory:Widget'])->assertSuccessful();
        $path = 'app/Modules/Inventory/Database/Migrations/2026_10_09_120000_create_widgets_table.php';
        expect($w->migration(dirname($path), 'create_widgets_table'))->toBe($path);
        $w->write($path, $w->read($path)."\n// developer edit\n");
        $missing = 'app/Modules/Inventory/Jobs/WidgetChild.php';
        $generated = $w->read($missing);
        $w->remove([$missing]);
        $creator->timestamp = '2026_10_10_130000';
        $before = array_map($w->read(...), array_combine($w->files(), $w->files()));
        $arguments = ['name' => 'Inventory:Widget', '--skip-existing' => true];
        $preview = json_decode($w->artisan('mod:migration-tree', [...$arguments, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['would_write'])->toBeTrue()->and($preview['warnings'])->toBe([])
            ->and(array_column($preview['files'], 'path'))->toBe([...($direct ? [] : ['app/Modules/Inventory/Models/Widget.php']), $path, $missing])
            ->and(array_column($preview['files'], 'existing'))->toBe([...($direct ? [] : ['keep']), 'keep', false])
            ->and(array_column($preview['files'], 'state'))->toBe([...($direct ? [] : ['unchanged']), 'edited']);
        $human = $w->artisan('mod:migration-tree', [...$arguments, '--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('will write 1 file')->expectsOutputToContain('migration'.($direct ? '' : ')').' (kept, exists, edited)');
        foreach ($preview['files'] as $file) {
            $human->expectsOutputToContain($file['path']);
        }
        expect(array_map($w->read(...), array_combine($w->files(), $w->files())))->toBe($before);
        $w->artisan('mod:migration-tree', $arguments)->assertSuccessful()->expectsOutputToContain('will write 1 file');
        expect($w->migration(dirname($path), 'create_widgets_table'))->toBe($path)
            ->and(array_values(array_diff($w->files(), array_keys($before))))->toBe([$missing])
            ->and($w->read($missing))->toBe($generated)
            ->and(array_map($w->read(...), array_combine(array_keys($before), array_keys($before))))->toBe($before);
    });
})->with(['companion' => false, 'direct' => true]);

it('refuses a plain migration with the same name at an earlier timestamp', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $path = 'app/Modules/Inventory/Database/Migrations/2020_01_02_030405_create_widgets_table.php';
        $w->write($path, '<?php // existing');
        $before = $w->files();
        $w->artisan('mod:migration', ['name' => 'Inventory:create_widgets_table'])->assertSuccessful()->expectsOutputToContain($path);
        expect($w->files())->toBe($before)->and($w->read($path))->toBe('<?php // existing');
    });
});
