<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E9 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'slices');
        Mod::layout('slices')->generates('presenter', fixed: 'Presenter');
        $workspace->write('stubs/mod/@slice/presenter.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E9.stub'));
        mkdir($workspace->root->path('app/Knowledge/IndexDocument'), 0700, true);
        $result = $workspace->artisan('mod:presenter', ['name' => 'Knowledge/IndexDocument:'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Presenter [app/Knowledge/IndexDocument/Presenter.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Knowledge/IndexDocument/Presenter.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E9.php.txt')));
        expect($workspace->files())->toBe(['app/Knowledge/IndexDocument/Presenter.php', 'stubs/mod/@slice/presenter.stub']);
    });
});
