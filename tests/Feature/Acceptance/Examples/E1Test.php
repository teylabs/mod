<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E1 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->write('stubs/mod/@module/Stories/story.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E1.stub'));
        mkdir($workspace->root->path('app/Modules/Agents/Stories'), 0700, true);
        $result = $workspace->artisan('mod:story', ['name' => 'Agents:ConversationWasStarted'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Story [app/Modules/Agents/Stories/ConversationWasStarted.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/Stories/ConversationWasStarted.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E1.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Agents/Stories/ConversationWasStarted.php', 'stubs/mod/@module/Stories/story.stub']);
    });
});
