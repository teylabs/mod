<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E8 fills a source slot from its option or prompt', function (bool $interactive) {
    Workspace::run(null, function (Workspace $workspace) use ($interactive) {
        TemplateScenario::webhook($workspace);
        if ($interactive) {
            TemplateScenario::testCase()->artisan('mod:webhook', ['name' => 'Knowledge:FileChanged'])
                ->expectsQuestion('Which source?', 'Drive')
                ->expectsOutputToContain('Created new source Drive.')
                ->expectsOutput(TemplateScenario::output($workspace, 'Webhook [app/Modules/Knowledge/Webhooks/Drive/FileChanged.php] created successfully.'))
                ->assertSuccessful();
        } else {
            $result = $workspace->artisan('mod:webhook', ['name' => 'Knowledge:FileChanged', '--source' => 'Drive'])->assertSuccessful();
            expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   INFO  Created new source Drive.  \n\n   INFO  Webhook [app/Modules/Knowledge/Webhooks/Drive/FileChanged.php] created successfully.  \n\n"));
        }
        expect(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Webhooks/Drive/FileChanged.php')))
            ->toBe(TemplateScenario::normalise($workspace, "<?php\n\nnamespace App\\Modules\\Knowledge\\Webhooks\\Drive;\n\n// Drive\nclass FileChanged\n{\n}\n"));
    });
})->with([true, false]);

it('E8 requires the source without a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::webhook($workspace);
        $result = $workspace->artisan('mod:webhook', ['name' => 'Knowledge:FileChanged'])->assertFailed();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   ERROR  mod:webhook needs a source. Pass --source=<source>.  \n\n"))
            ->and($workspace->exists('app/Modules/Knowledge/Webhooks'))->toBeFalse();
    });
});
