<?php

use Composer\Autoload\ClassLoader;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E10 places workflow actions in slash and dot nested domains', function (string $prefix) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($prefix) {
        config()->set('mod.layout', 'ddd');
        $loader = new ClassLoader;
        $loader->addPsr4('Domain\\', $workspace->root->path('src/Domain'));
        $loader->register();
        Mod::layout('ddd')->generates('workflow', in: '{domain}/Workflows');
        $workspace->write('stubs/mod.workflow.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    public function handle(): void\n    {\n        //\n    }\n}\n");
        // Existing group: this example's output contains no new-group notice.
        mkdir($workspace->root->path('src/Domain/Agents/Chat'), 0700, true);
        $result = $workspace->artisan('mod:workflow', ['name' => $prefix.':EscalateConversation']);
        $loader->unregister();
        $result->assertSuccessful();
        expect(str_replace("\r\n", "\n", $result->output))->toBe("\n   INFO  Workflow [src/Domain/Agents/Chat/Workflows/EscalateConversation.php] created successfully.  \n\n")
            ->and($workspace->files())->toBe(['src/Domain/Agents/Chat/Workflows/EscalateConversation.php', 'stubs/mod.workflow.stub'])
            ->and(str_replace("\r\n", "\n", $workspace->read('src/Domain/Agents/Chat/Workflows/EscalateConversation.php')))->toBe("<?php\n\nnamespace Domain\\Agents\\Chat\\Workflows;\n\nclass EscalateConversation\n{\n    public function handle(): void\n    {\n        //\n    }\n}\n");
    });
})->with(['Agents/Chat', 'Agents.Chat']);
