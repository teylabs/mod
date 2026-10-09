<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E5 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->write('stubs/mod/@module/Concerns/concern.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E5.stub'));
        mkdir($workspace->root->path('app/Modules/Knowledge/Concerns'), 0700, true);
        $result = $workspace->artisan('mod:concern', ['name' => 'Knowledge:BelongsToDocument'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Concern [app/Modules/Knowledge/Concerns/BelongsToDocument.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Concerns/BelongsToDocument.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E5.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Concerns/BelongsToDocument.php', 'stubs/mod/@module/Concerns/concern.stub']);
    });
});
