<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E13 follows the type-first wildcard with an optional feature', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'type-first');
        Mod::layout('type-first')->generates('tool', in: '@feature/Tools', nested: true);
        $workspace->write('stubs/mod.tool.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
        mkdir($workspace->root->path('app/Tools/Agents'), 0700, true);
        foreach (['Agents:SearchDocuments' => ['Agents/', 'SearchDocuments'], 'SearchEverything' => ['', 'SearchEverything']] as $name => [$folder, $class]) {
            $result = $workspace->artisan('mod:tool', ['name' => $name]);
            $result->assertSuccessful();
            expect(str_replace("\r\n", "\n", $result->output))->toBe("\n   INFO  Tool [app/Tools/{$folder}{$class}.php] created successfully.  \n\n")
                ->and(str_replace("\r\n", "\n", $workspace->read("app/Tools/{$folder}{$class}.php")))->toBe("<?php\n\nnamespace App\\Tools".($folder === '' ? '' : '\\Agents').";\n\nclass {$class}\n{\n}\n");
        }
        expect($workspace->files())->toBe(['app/Tools/Agents/SearchDocuments.php', 'app/Tools/SearchEverything.php', 'stubs/mod.tool.stub']);
    });
});
