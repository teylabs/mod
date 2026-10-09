<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M16 offers the source option for a slash value in a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::webhook($workspace, true);
        TemplateScenario::testCase()->artisan('mod:webhook', ['name' => 'Knowledge/Drive:FileChanged'])
            ->expectsConfirmation('[Drive] looks like a source. Use --source=Drive?', 'yes')
            ->expectsOutput(TemplateScenario::output($workspace, 'Webhook [app/Modules/Knowledge/Webhooks/Drive/FileChanged.php] created successfully.'))
            ->assertSuccessful();
        expect($workspace->exists('app/Modules/Knowledge/Webhooks/Drive/FileChanged.php'))->toBeTrue();
    });
});

it('M16 gives the corrected command without a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::webhook($workspace);
        $result = $workspace->artisan('mod:webhook', ['name' => 'Knowledge/Drive:FileChanged'])->assertFailed();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   ERROR  [Drive] looks like a source. Pass it with its option: mod:webhook Knowledge:FileChanged --source=Drive.  \n\n"))
            ->and($workspace->exists('app/Modules/Knowledge/Webhooks'))->toBeFalse();
    });
});
