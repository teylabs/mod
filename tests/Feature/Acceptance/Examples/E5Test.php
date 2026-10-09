<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
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

it('E5 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'modules');
        $result = $w->artisan('mod:template', ['type' => 'trait', 'path' => 'concern'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Concerns/concern', [
            'Starts as' => 'a trait', 'Command' => 'mod:concern',
            'Writes' => 'app/Modules/<module>/Concerns/<Name>.php', 'Try' => 'php artisan mod:concern Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Concerns/concern.stub')))->toBe(CreationScenario::fixture('trait'))
            ->and($w->files())->toBe(['stubs/mod/@module/Concerns/concern.stub']);
    });
});
