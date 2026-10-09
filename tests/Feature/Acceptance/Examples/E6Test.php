<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E6 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->generates('status', suffix: 'Status');
        $workspace->write('stubs/mod/@module/Enums/status.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E6.stub'));
        mkdir($workspace->root->path('app/Modules/Knowledge/Enums'), 0700, true);
        $result = $workspace->artisan('mod:status', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Status [app/Modules/Knowledge/Enums/DocumentStatus.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Enums/DocumentStatus.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E6.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Enums/DocumentStatus.php', 'stubs/mod/@module/Enums/status.stub']);
    });
});
