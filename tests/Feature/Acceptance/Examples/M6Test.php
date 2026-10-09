<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M6 refuses @module/Payloads/payload without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => 'dot', 'path' => 'payload'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::errors(['There is no type [dot]. Did you mean [dto]?', 'Types: class, interface, trait, enum, or a file type of the modules layout (php artisan mod:list).']))->and($w->files())->toBe([]);
    });
});

it('M6 accepts its correction in a terminal for @module/Payloads/payload', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => 'dot', 'path' => 'payload'])
            ->expectsQuestion('There is no type [dot]. Which type?', 'dto')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Payloads/payload.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Payloads/payload.stub')))->toBe(CreationScenario::fixture('dto'));
    });
});

it('M6 refuses @module/Tools/tool without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => '@module/Tools/tool', 'path' => 'class'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::error('The type comes first: mod:template class @module/Tools/tool.'))->and($w->files())->toBe([]);
    });
});

it('M6 accepts its correction in a terminal for @module/Tools/tool', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => '@module/Tools/tool', 'path' => 'class'])
            ->expectsConfirmation('The type comes first. Run mod:template class @module/Tools/tool?', 'yes')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/@module/Tools/tool.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});
