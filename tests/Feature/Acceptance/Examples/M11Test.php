<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M11 refuses unresolved source without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        $before = $w->files();
        expect($w->artisan('mod:template', ['--from' => null])->assertFailed()->normalisedOutput())->toBe(CreationScenario::error('--from needs a class name or a file path, such as --from=DocumentWasUploaded.'))
            ->and($w->files())->toBe($before);
    });
});

it('M11 resolves the source in a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        TemplateScenario::testCase()->artisan('mod:template', ['--from' => null])
            ->expectsQuestion('Which class should the template start from?', 'DocumentWasUploaded')
            ->expectsChoice('Which class should the template start from?', 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', ['App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded'])
            ->expectsQuestion('Where should the template live?', '@module/Stories')
            ->expectsQuestion('What should the template be called?', 'story')
            ->assertSuccessful();
        expect($w->exists('stubs/mod/@module/Stories/story.stub'))->toBeTrue();
    });
});
