<?php

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
