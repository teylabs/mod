<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('matches an existing migration by its name across timestamps', function (string $mode, bool $direct) {
    Workspace::run(null, function (Workspace $w) use ($mode, $direct) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('crud', fn (Scaffold $s) => $direct
            ? $s->makes('migration', name: 'create_widgets_table')
            : $s->makes('model', options: ['--migration']));
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
})->with(['refuse', 'skip', 'force'])->with([false, true]);

it('refuses a plain migration with the same name at an earlier timestamp', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $path = 'app/Modules/Inventory/Database/Migrations/2020_01_02_030405_create_widgets_table.php';
        $w->write($path, '<?php // existing');
        $before = $w->files();
        $w->artisan('mod:migration', ['name' => 'Inventory:create_widgets_table'])->assertSuccessful()->expectsOutputToContain($path);
        expect($w->files())->toBe($before);
    });
});
