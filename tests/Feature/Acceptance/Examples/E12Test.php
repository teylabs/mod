<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E12 writes a template without an anchor relative to app', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace, 'laravel');
        $result = $workspace->artisan('mod:tool', ['name' => 'SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   INFO  Tool [app/Tools/SearchDocuments.php] created successfully.  \n\n"))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Tools/SearchDocuments.php')))
            ->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content('App\\Tools', 'SearchDocuments')));
    });
});

it('E12 offers to drop placement in a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace, 'laravel');
        TemplateScenario::testCase()->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])
            ->expectsConfirmation('Layout [laravel] takes no placement. Write app/Tools/SearchDocuments.php without [Agents:]?', 'yes')
            ->expectsOutput(TemplateScenario::output($workspace, 'Tool [app/Tools/SearchDocuments.php] created successfully.'))
            ->assertSuccessful();
        expect($workspace->exists('app/Tools/SearchDocuments.php'))->toBeTrue();
    });
});

it('E12 refuses placement without a terminal', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace, 'laravel');
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertFailed();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   ERROR  Layout [laravel] takes no placement; drop the [Agents:] prefix.  \n\n"))
            ->and($workspace->exists('app/Tools/SearchDocuments.php'))->toBeFalse();
    });
});
