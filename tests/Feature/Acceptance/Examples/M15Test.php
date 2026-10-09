<?php

use Pest\TestSuite;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

function m15Layout(Workspace $workspace): void
{
    putenv('COLUMNS=72');
    config()->set('mod.layout', 'modules');
    Mod::layout('modules')->generates('tool', in: '@module/Tools', nested: true);
    $workspace->write('stubs/mod.tool.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
    mkdir($workspace->root->path('app/Modules/Knowledge'), 0700, true);
}

it('M15 refuses a nested module without a terminal and explains both fixes', function () {
    Workspace::run(null, function (Workspace $workspace) {
        m15Layout($workspace);
        $result = $workspace->artisan('mod:tool', ['name' => 'Knowledge/Drive:Search']);
        $result->assertFailed();
        expect($result->normalisedOutput())->toBe("\n   ERROR  Modules don't nest. Use a module of its own (Drive:Search), or a subfolder in the name (Knowledge:Drive/Search).  \n\n")
            ->and($workspace->files())->toBe(['stubs/mod.tool.stub']);
    });
});

it('M15 asks which flat form was intended and continues', function () {
    Workspace::run(null, function (Workspace $workspace) {
        m15Layout($workspace);
        $case = TestSuite::getInstance()->test;
        if (! $case instanceof TestCase) {
            throw new RuntimeException('M15 needs Testbench.');
        }
        $createdOutput = '';
        $case->artisan('mod:tool', ['name' => 'Knowledge/Drive:Search'])
            ->expectsChoice("Modules don't nest. Which did you mean?", 'Knowledge:Drive/Search', ['Drive:Search', 'Knowledge:Drive/Search'])
            ->expectsOutput(Mockery::on(function (string $output) use (&$createdOutput): bool {
                if (! str_contains($output, 'created successfully.')) {
                    return false;
                }
                $createdOutput = $output;

                return true;
            }))
            ->assertSuccessful();
        expect((new CommandResult(0, $createdOutput, $workspace->root->path))->normalisedOutput())
            ->toContain('Tool [app/Modules/Knowledge/Tools/Drive/Search.php] created successfully.')
            ->and($workspace->files())->toBe(['app/Modules/Knowledge/Tools/Drive/Search.php', 'stubs/mod.tool.stub'])
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Knowledge/Tools/Drive/Search.php')))->toBe("<?php\n\nnamespace App\\Modules\\Knowledge\\Tools\\Drive;\n\nclass Search\n{\n}\n");
    });
});
