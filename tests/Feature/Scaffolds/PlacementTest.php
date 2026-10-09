<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('does not carry an ungrouped member placement into the following member or tree part', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/.gitkeep', '');
        Mod::scaffold('mixed', function (Scaffold $s) use ($tree) {
            $s->makes('interface', name: 'Shared', ungrouped: true, existing: 'keep')->makes('job');
            if ($tree) {
                $s->asks('extras', type: 'list', default: ['Widget'])->each('extras', 'extra')->part('extra', fn ($p) => $p->makes('job', name: 'Extra{name}'));
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
        expect($result->normalisedOutput())->toContain('Inventory', '--area=Inventory')
            ->and($w->exists('app/Modules/Inventry/Jobs/Widget.php'))->toBeFalse();
    });
});

it('checks a plain member group typo and describes new plain member groups', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Models/.gitkeep', '');
        $w->write('stubs/mod/@module/resources/js/helpers/helper.ts.stub', 'export const {{ name }} = true;');
        Mod::scaffold('plain-area', fn (Scaffold $s) => $s->asks('area')->makes('helper', group: '{{ area }}'));
        $result = $w->artisan('mod:plain-area', ['name' => 'Inventory:widget', '--area' => 'Inventry'])->assertFailed();
        expect($result->normalisedOutput())->toContain('Inventory', '--area=Inventory')
            ->and($w->exists('app/Modules/Inventry/resources/js/helpers/widget.ts'))->toBeFalse();
        $result = $w->artisan('mod:plain-area', ['name' => 'Inventory:widget', '--area' => 'Backoffice'])->assertSuccessful();
        expect($result->normalisedOutput())->toContain('Created new module Backoffice.')
            ->and($w->exists('app/Modules/Backoffice/resources/js/helpers/widget.ts'))->toBeTrue();
    });
});

it('keeps an existing tree member even with force and reports only new writes', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Shared.php', "<?php\r\n// shared\r\n");
        Mod::scaffold('tree-keep', fn (Scaffold $s) => $s->makes('interface', name: 'Shared', ungrouped: true, existing: 'keep')
            ->asks('extras', type: 'list', default: ['One'])->each('extras', 'extra')
            ->part('extra', fn ($p) => $p->makes('job', name: '{extra}{name}')));
        $result = $w->artisan('mod:tree-keep', ['name' => 'Inventory:Widget', '--force' => true])->assertSuccessful();
        expect($w->read('app/Shared.php'))->toBe("<?php\r\n// shared\r\n")
            ->and($result->normalisedOutput())->toContain('will write 1 file for Inventory:Widget.', 'kept, exists');
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

it('suggests a member group typo in a terminal and accepts the selected group', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/.gitkeep', '');
        Mod::scaffold('two-groups', fn (Scaffold $s) => $s->asks('area')->makes('job', group: '{{ area }}'));
        Examples::testCase()->artisan('mod:two-groups', ['name' => 'Inventory:Widget', '--area' => 'Inventry'])
            ->expectsQuestion("Inventry doesn't exist. Did you mean Inventory?", 'Inventory')
            ->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Jobs/Widget.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Inventry/Jobs/Widget.php'))->toBeFalse();
    });
});

it('keeps only the keep member when the scaffold collision choice overwrites the others', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/Widget.php', "<?php\r\n// old job\r\n");
        $w->write('app/Shared.php', "<?php\r\n// shared\r\n");
        Mod::scaffold('keep-one', fn (Scaffold $s) => $s->makes('interface', name: 'Shared', ungrouped: true, existing: 'keep')->makes('job'));
        Examples::testCase()->artisan('mod:keep-one', ['name' => 'Inventory:Widget'])
            ->expectsChoice('1 file already exists. What should happen?', 'Overwrite it', ['Keep it, and write the other 0', 'Overwrite it', 'Cancel'])
            ->assertSuccessful();
        expect($w->read('app/Shared.php'))->toBe("<?php\r\n// shared\r\n")
            ->and($w->read('app/Modules/Inventory/Jobs/Widget.php'))->toContain('class Widget');
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

it('retains the default group after a member chooses its own group', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Jobs/.gitkeep', '');
        $w->write('app/Modules/Backoffice/Jobs/.gitkeep', '');
        Mod::scaffold('separate', function (Scaffold $s) use ($tree) {
            $s->makes('job', as: 'other', name: 'Other', group: 'Backoffice')->makes('job');
            if ($tree) {
                $s->part('extra', fn ($p) => $p->makes('job'));
            }
        });
        $w->artisan('mod:separate', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->exists('app/Modules/Backoffice/Jobs/Other.php'))->toBeTrue()
            ->and($w->exists('app/Modules/Inventory/Jobs/Widget.php'))->toBeTrue();
    });
})->with([false, true]);

it('keeps explicit keep members silently after displaying the plan', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Shared.php', "<?php\r\n// shared\r\n");
        Mod::scaffold('shared-contract', fn (Scaffold $s) => $s->makes('interface', name: 'Shared', ungrouped: true, existing: 'keep'));
        $result = $w->artisan('mod:shared-contract', ['name' => 'Inventory:Widget', '--force' => true])->assertSuccessful();
        expect($w->read('app/Shared.php'))->toBe("<?php\r\n// shared\r\n")
            ->and($result->normalisedOutput())->toContain('kept, exists');
        expect($result->normalisedOutput())->not->toContain('Kept app/Shared.php.');
    });
});

it('uses an ungrouped plain member name inside a tree recipe', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod/resources/js/helpers/helper.ts.stub', 'export const {{ name }} = true;');
        Mod::scaffold('shared-helper', fn (Scaffold $s) => $s->makes('helper', name: 'useShared', ungrouped: true)->part('extra', fn ($p) => $p->makes('job')));
        $w->artisan('mod:shared-helper', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->read('resources/js/helpers/useShared.ts'))->toBe('export const useShared = true;');
    });
});
