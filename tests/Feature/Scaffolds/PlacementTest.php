<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('does not carry an ungrouped member placement into the following member or tree part', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/.gitkeep', '');
        Mod::scaffold('mixed', function (Scaffold $s) use ($tree) {
            $s->makes('interface', name: 'Shared', ungrouped: true, existing: 'keep')->makes('job');
            if ($tree) {
                $s->part('extra', fn ($p) => $p->makes('job', name: 'Extra{name}'));
            }
        });
        $w->artisan('mod:mixed', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->exists('app/Shared.php'))->toBeTrue()->and($w->exists('app/Modules/Inventory/Jobs/Widget.php'))->toBeTrue();
        if ($tree) {
            expect($w->exists('app/Modules/Inventory/Jobs/ExtraWidget.php'))->toBeTrue();
        }
    });
})->with([false, true]);

it('refuses a member group typo before writing and names the group flag', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/.gitkeep', '');
        Mod::scaffold('two-groups', fn (Scaffold $s) => $s->asks('area')->makes('job', group: '{{ area }}'));
        $result = $w->artisan('mod:two-groups', ['name' => 'Inventory:Widget', '--area' => 'Inventry'])->assertFailed();
        expect($result->normalisedOutput())->toContain('Inventory', '--module=Inventory')
            ->and($w->exists('app/Modules/Inventry/Jobs/Widget.php'))->toBeFalse();
    });
});

it('announces a new member group through the ordinary group notice', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('new-group', fn (Scaffold $s) => $s->asks('area')->makes('job', group: '{{ area }}'));
        $result = $w->artisan('mod:new-group', ['name' => 'Inventory:Widget', '--area' => 'Backoffice'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('Backoffice', 'new')
            ->and($w->exists('app/Modules/Backoffice/Jobs/Widget.php'))->toBeTrue();
    });
});

it('rejects contradictory placement and unsupported collision policies as recipe problems', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('bad-placement', fn (Scaffold $s) => $s->makes('job', ungrouped: true, group: 'Inventory'));
        Mod::scaffold('bad-existing', fn (Scaffold $s) => $s->makes('job', existing: 'overwrite'));
        expect($w->artisan('mod:bad-placement', ['name' => 'Inventory:Widget'])->assertFailed()->normalisedOutput())->toContain('ungrouped', 'group')
            ->and($w->artisan('mod:bad-existing', ['name' => 'Inventory:Widget'])->assertFailed()->normalisedOutput())->toContain('keep');
        expect($w->files())->toBe([]);
    });
});
