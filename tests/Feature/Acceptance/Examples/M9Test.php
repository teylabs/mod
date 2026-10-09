<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M9 skips a hand-made leading app path and keeps the valid generator', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/app/Support/Tools/broken.stub', TemplateScenario::CLASS_STUB);
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   WARN  Skipped template [stubs/mod/app/Support/Tools/broken.stub]: Template paths are relative to app/, like ->generates(in:). Drop app/: Support/Tools/broken.stub.  \n\n\n   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  \n\n"))
            ->and($workspace->exists('app/app'))->toBeFalse();
    });
});

it('M9 refuses Support/Tools/tool without a terminal and writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $result = $w->artisan('mod:template', ['type' => 'app/Support/Tools/tool'])->assertFailed();
        expect($result->normalisedOutput())->toBe(CreationScenario::error('Template paths are relative to app/, like ->generates(in:). Drop app/: mod:template Support/Tools/tool.'))->and($w->files())->toBe([]);
    });
});

it('M9 accepts its correction in a terminal for Support/Tools/tool', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        TemplateScenario::testCase()->artisan('mod:template', ['type' => 'app/Support/Tools/tool'])
            ->expectsConfirmation('Template paths are relative to app/. Use Support/Tools/tool?', 'yes')
            ->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod/Support/Tools/tool.stub']);
        expect(str_replace("\r\n", "\n", $w->read('stubs/mod/Support/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'));
    });
});
