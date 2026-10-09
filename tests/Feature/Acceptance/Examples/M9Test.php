<?php

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
