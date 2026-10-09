<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E4 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->write('stubs/mod/@module/Contracts/contract.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E4.stub'));
        mkdir($workspace->root->path('app/Modules/Knowledge/Contracts'), 0700, true);
        $result = $workspace->artisan('mod:contract', ['name' => 'Knowledge:HasEmbeddings'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Contract [app/Modules/Knowledge/Contracts/HasEmbeddings.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Contracts/HasEmbeddings.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E4.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Contracts/HasEmbeddings.php', 'stubs/mod/@module/Contracts/contract.stub']);
    });
});

it('E4 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'modules');
        $result = $w->artisan('mod:template', ['type' => 'interface', 'path' => 'contract'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Contracts/contract', [
            'Starts as' => 'an interface', 'Command' => 'mod:contract',
            'Writes' => 'app/Modules/<module>/Contracts/<Name>.php', 'Try' => 'php artisan mod:contract Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Contracts/contract.stub')))->toBe(CreationScenario::fixture('interface'))
            ->and($w->files())->toBe(['stubs/mod/@module/Contracts/contract.stub']);
    });
});
