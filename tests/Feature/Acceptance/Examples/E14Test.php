<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E14 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'ddd');

        $workspace->write('stubs/mod/Modules/@domain/Presenters/presenter.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E14.stub'));
        mkdir($workspace->root->path('app/Modules/Knowledge/Presenters'), 0700, true);
        $result = $workspace->artisan('mod:presenter', ['name' => 'Knowledge:DocumentPresenter'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Presenter [app/Modules/Knowledge/Presenters/DocumentPresenter.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Presenters/DocumentPresenter.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E14.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Presenters/DocumentPresenter.php', 'stubs/mod/Modules/@domain/Presenters/presenter.stub']);
    });
});
