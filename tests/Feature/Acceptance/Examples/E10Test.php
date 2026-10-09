<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Support\ComposerJson;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E10 places workflow actions in slash and dot nested domains', function (string $prefix) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($prefix) {
        config()->set('mod.layout', 'ddd');
        $composer = new ComposerJson($workspace->root->path('composer.json'));
        $composer->register('Domain\\', 'src/Domain');
        $composer->save();
        Mod::layout('ddd')->generates('workflow', in: '{domain}/Workflows');
        $workspace->write('stubs/mod.workflow.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    public function handle(): void\n    {\n        //\n    }\n}\n");
        // Existing group: this example's output contains no new-group notice.
        mkdir($workspace->root->path('src/Domain/Agents/Chat'), 0700, true);
        $result = $workspace->artisan('mod:workflow', ['name' => $prefix.':EscalateConversation']);
        $result->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   INFO  Workflow [src/Domain/Agents/Chat/Workflows/EscalateConversation.php] created successfully.  \n\n")
            ->and($workspace->files())->toBe(['src/Domain/Agents/Chat/Workflows/EscalateConversation.php', 'stubs/mod.workflow.stub'])
            ->and(str_replace("\r\n", "\n", $workspace->read('src/Domain/Agents/Chat/Workflows/EscalateConversation.php')))->toBe("<?php\n\nnamespace Domain\\Agents\\Chat\\Workflows;\n\nclass EscalateConversation\n{\n    public function handle(): void\n    {\n        //\n    }\n}\n");
    });
})->with(['Agents/Chat', 'Agents.Chat']);

it('E10 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'ddd');
        $result = $w->artisan('mod:template', ['type' => 'action', 'path' => 'workflow'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@domain/Workflows/workflow', [
            'Starts as' => 'an action (a class with handle())', 'Command' => 'mod:workflow',
            'Writes' => 'src/Domain/<domain>/Workflows/<Name>.php', 'Try' => 'php artisan mod:workflow Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@domain/Workflows/workflow.stub')))->toBe(CreationScenario::fixture('action'))
            ->and($w->files())->toBe(['stubs/mod/@domain/Workflows/workflow.stub']);
    });
});
