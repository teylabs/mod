<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
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

it('E6 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'modules');
        $result = $w->artisan('mod:template', ['type' => 'enum', 'path' => '@module/Enums/status'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Enums/status', [
            'Starts as' => 'an enum', 'Command' => 'mod:status',
            'Writes' => 'app/Modules/<module>/Enums/<Name>.php', 'Try' => 'php artisan mod:status Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Enums/status.stub')))->toBe(CreationScenario::fixture('enum'))
            ->and($w->files())->toBe(['stubs/mod/@module/Enums/status.stub']);
    });
});
