<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M1 asks for a missing module in a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        TemplateScenario::testCase()->artisan('mod:tool', ['name' => 'SearchDocuments'])
            ->expectsQuestion('Which module?', 'Agents')
            ->expectsOutput(TemplateScenario::output($workspace, 'Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.'))
            ->assertSuccessful();
        expect(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/Tools/SearchDocuments.php')))
            ->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content('App\\Modules\\Agents\\Tools', 'SearchDocuments')));
    });
});

it('M1 names all ways to supply the module without a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $result = $workspace->artisan('mod:tool', ['name' => 'SearchDocuments'])->assertFailed();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   ERROR  mod:tool needs a module. Pass --module=<module>, --in=<module>, or prefix the name: <module>:SearchDocuments.  \n\n"))
            ->and($workspace->exists('app/Modules/Agents/Tools/SearchDocuments.php'))->toBeFalse();
    });
});
