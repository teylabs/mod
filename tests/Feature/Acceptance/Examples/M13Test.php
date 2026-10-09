<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M13 refuses unresolved source without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        $before = $w->files();
        expect($w->artisan('mod:template', ['--from' => 'DocumentWasUplaoded'])->assertFailed()->normalisedOutput())->toBe(CreationScenario::errors(['There is no class [DocumentWasUplaoded] in the app.', 'Did you mean DocumentWasUploaded (App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded)?']))
            ->and($w->files())->toBe($before);
    });
});

it('M13 resolves the source in a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        TemplateScenario::testCase()->artisan('mod:template', ['--from' => 'DocumentWasUplaoded'])
            ->expectsConfirmation('There is no class DocumentWasUplaoded. Did you mean DocumentWasUploaded?', 'yes')
            ->expectsQuestion('Where should the template live?', '@module/Stories')
            ->expectsQuestion('What should the template be called?', 'story')
            ->assertSuccessful();
        expect($w->exists('stubs/mod/@module/Stories/story.stub'))->toBeTrue();
    });
});
