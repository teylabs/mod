<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M10 refuses unresolved source without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        $before = $w->files();
        expect($w->artisan('mod:template', ['--from' => 'AppModulesKnowledgeStoriesDocumentWasUploaded'])->assertFailed()->normalisedOutput())->toBe(CreationScenario::errors(['There is no class or file [AppModulesKnowledgeStoriesDocumentWasUploaded].', 'Your shell may have removed the backslashes. Did you mean App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded?', 'Quote it, or use slashes: --from=App/Modules/Knowledge/Stories/DocumentWasUploaded']))
            ->and($w->files())->toBe($before);
    });
});

it('M10 resolves the source in a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        TemplateScenario::testCase()->artisan('mod:template', ['--from' => 'AppModulesKnowledgeStoriesDocumentWasUploaded'])
            ->expectsConfirmation('Your shell may have removed the backslashes. Use App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded?', 'yes')
            ->expectsQuestion('Where should the template live?', '@module/Stories')
            ->expectsQuestion('What should the template be called?', 'story')
            ->assertSuccessful();
        expect($w->exists('stubs/mod/@module/Stories/story.stub'))->toBeTrue();
    });
});

it('M10 keeps quoting advice when a namespace-prefixed value has no match', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->artisan('mod:template', ['--from' => 'AppMissingStory'])->assertFailed()->expectsOutputToContain('Your shell may have removed the backslashes. Quote the class name, or use slashes: --from=App/Path/To/Class.');
        expect($w->files())->toBe([]);
    });
});
