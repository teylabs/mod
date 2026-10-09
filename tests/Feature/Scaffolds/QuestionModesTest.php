<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('asks text, comma lists and confirms with one final write question', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        Mod::scaffold('questions', fn (Scaffold $s) => $s->asks('label', label: 'Label')
            ->asks('tabs', type: 'list', label: 'Tabs')->asks('enabled', type: 'confirm', label: 'Enabled')->makes('model'));
        Examples::testCase()->artisan('mod:questions', ['name' => 'Inventory:Widget'])
            ->expectsQuestion('Label', 'Widget')->expectsQuestion('Tabs', 'Overview, Details')
            ->expectsConfirmation('Enabled', 'yes')->expectsConfirmation('Write these 1 files?', 'no')->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse();
    });
});

it('names both boolean flags when a confirm has no default without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/.keep', '');
        Mod::scaffold('questions', fn (Scaffold $s) => $s->asks('enabled', type: 'confirm')->makes('model'));
        $result = $w->artisan('mod:questions', ['name' => 'Inventory:Widget'])->assertFailed();
        expect($result->normalisedOutput())->toBe("\n   ERROR  mod:questions needs a enabled. Pass --enabled or --no-enabled.  \n\n");
        expect($w->exists('app/Modules/Inventory/Models/Widget.php'))->toBeFalse();
    });
});
