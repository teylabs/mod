<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M12 refuses unresolved source without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        $w->write('app/Modules/Agents/Stories/DocumentWasUploaded.php', str_replace('Knowledge', 'Agents', CreationScenario::fixture('story_source')));
        $before = $w->files();
        expect($w->artisan('mod:template', ['--from' => 'DocumentWasUploaded'])->assertFailed()->normalisedOutput())->toBe(CreationScenario::errors(['Several classes are named [DocumentWasUploaded]:', 'App\\Modules\\Agents\\Stories\\DocumentWasUploaded', 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', 'Add part of the namespace to choose one, such as --from=Knowledge/DocumentWasUploaded.']))
            ->and($w->files())->toBe($before);
    });
});

it('M12 resolves the source in a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', CreationScenario::fixture('story_source'));
        $w->write('app/Modules/Agents/Stories/DocumentWasUploaded.php', str_replace('Knowledge', 'Agents', CreationScenario::fixture('story_source')));
        TemplateScenario::testCase()->artisan('mod:template', ['--from' => 'DocumentWasUploaded'])
            ->expectsChoice('Several classes are named DocumentWasUploaded. Which one?', 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', ['App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', 'App\\Modules\\Agents\\Stories\\DocumentWasUploaded'])
            ->expectsQuestion('Where should the template live?', '@module/Stories')
            ->expectsQuestion('What should the template be called?', 'story')
            ->assertSuccessful();
        expect($w->exists('stubs/mod/@module/Stories/story.stub'))->toBeTrue();
    });
});
