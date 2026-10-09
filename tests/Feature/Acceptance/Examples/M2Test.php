<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M2 suggests a likely typo in a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        TemplateScenario::testCase()->artisan('mod:tool', ['name' => 'Agnets:SearchDocuments'])
            ->expectsQuestion("Agnets doesn't exist. Did you mean Agents?", 'Agents')
            ->expectsOutput(TemplateScenario::output($workspace, 'Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.'))
            ->assertSuccessful();
        expect($workspace->exists('app/Modules/Agnets'))->toBeFalse();
    });
});

it('M2 creates a likely typo with the settled hint without a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $result = $workspace->artisan('mod:tool', ['name' => 'Agnets:SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   INFO  Created new module Agnets (did you mean Agents?).  \n\n   INFO  Tool [app/Modules/Agnets/Tools/SearchDocuments.php] created successfully.  \n\n")
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Agnets/Tools/SearchDocuments.php')))
            ->toBe(TemplateScenario::content('App\\Modules\\Agnets\\Tools', 'SearchDocuments'));
    });
});

it('M2 uses an existing group with the correct case', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $result = $workspace->artisan('mod:tool', ['name' => 'agents:SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   INFO  Using existing module Agents (you typed agents).  \n\n   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  \n\n");
    });
});
