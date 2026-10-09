<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M14 refuses @module/Tools/tool without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => 'Tools/@module/tool'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::error('The modules layout keeps modules in Modules/, not Tools/. Did you mean @module/Tools/tool?'))->and($w->files())->toBe([]);
    });
});

it('M14 accepts its correction in a terminal for @module/Tools/tool', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => 'Tools/@module/tool'])
            ->expectsConfirmation('The modules layout keeps modules in Modules/, not Tools/. Use @module/Tools/tool?', 'yes')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Tools/tool.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});

it('M14 removes a valid redundant prefix', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->artisan('mod:template', ['type' => 'Modules/@module/Tools/tool'])->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Tools/tool.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});
