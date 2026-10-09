<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M7 refuses @module/Tools/tool without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => '@modlue/Tools/tool'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::error('There is no anchor [@modlue]. Did you mean @module? The modules layout\'s anchors: @module, @group.'))->and($w->files())->toBe([]);
    });
});

it('M7 accepts its correction in a terminal for @module/Tools/tool', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => '@modlue/Tools/tool'])
            ->expectsConfirmation('There is no anchor [@modlue]. Did you mean @module?', 'yes')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Tools/tool.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('M7 accepts a real foreign anchor and reports the parser notice', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $r = $w->artisan('mod:template', ['type' => '@domain/Tools/tool'])->assertSuccessful();
        expect($r->normalisedOutput())->toBe("\n   INFO  Template anchor @domain resolves to @module in layout [modules].  \n\n".ltrim(CreationScenario::output('@domain/Tools/tool', [
            'Starts as' => 'a class', 'Command' => 'mod:tool', 'Writes' => 'app/Modules/<module>/Tools/<Name>.php', 'Try' => 'php artisan mod:tool Agents:<Name>',
        ]), "\n"))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@domain/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});
