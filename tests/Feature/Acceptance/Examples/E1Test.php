<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
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

it('E1 extracts the same source through every supported form without loading it', function (string $from) {
    Workspace::run(null, function (Workspace $w) use ($from) {
        CreationScenario::setup($w);
        $source = CreationScenario::fixture('story_source');
        $w->write('app/Modules/Knowledge/Stories/DocumentWasUploaded.php', $source);
        $result = $w->artisan('mod:template', ['--from' => $from, '--into' => '@module/Stories/story'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Stories/story', [
            'Starts as' => 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded',
            'Replaced' => 'namespace (line 3), DocumentWasUploaded (line 10)',
            'Command' => 'mod:story', 'Writes' => 'app/Modules/<module>/Stories/<Name>.php',
            'Try' => 'php artisan mod:story Agents:<Name>',
        ], true, 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded'))
            ->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Stories/story.stub')))->toBe(str_replace(['namespace App\\Modules\\Knowledge\\Stories;', 'class DocumentWasUploaded'], ['namespace {{ namespace }};', 'class {{ class }}'], $source))
            ->and(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Stories/DocumentWasUploaded.php')))->toBe($source)
            ->and(class_exists('App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', false))->toBeFalse();
    });
})->with(['DocumentWasUploaded', 'Stories/DocumentWasUploaded', 'App/Modules/Knowledge/Stories/DocumentWasUploaded', 'App\\Modules\\Knowledge\\Stories\\DocumentWasUploaded', 'app/Modules/Knowledge/Stories/DocumentWasUploaded.php']);
