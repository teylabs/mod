<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
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
            expect($result->normalisedOutput())->toBe("\n   INFO  Tool [app/Tools/{$folder}{$class}.php] created successfully.  \n\n")
                ->and(str_replace("\r\n", "\n", $workspace->read("app/Tools/{$folder}{$class}.php")))->toBe("<?php\n\nnamespace App\\Tools".($folder === '' ? '' : '\\Agents').";\n\nclass {$class}\n{\n}\n");
        }
        expect($workspace->files())->toBe(['app/Tools/Agents/SearchDocuments.php', 'app/Tools/SearchEverything.php', 'stubs/mod.tool.stub']);
    });
});

it('E13 keeps the feature optional for a scanned template too', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'type-first');
        $workspace->write('stubs/mod/@feature/Tools/tool.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
        mkdir($workspace->root->path('app/Tools/Agents'), 0700, true);
        foreach (['Agents:SearchDocuments' => ['Agents/', 'SearchDocuments'], 'SearchEverything' => ['', 'SearchEverything']] as $name => [$folder, $class]) {
            $result = $workspace->artisan('mod:tool', ['name' => $name])->assertSuccessful();
            expect($result->normalisedOutput())->toBe("\n   INFO  Tool [app/Tools/{$folder}{$class}.php] created successfully.  \n\n")
                ->and(str_replace("\r\n", "\n", $workspace->read("app/Tools/{$folder}{$class}.php")))->toBe("<?php\n\nnamespace App\\Tools".($folder === '' ? '' : '\\Agents').";\n\nclass {$class}\n{\n}\n");
        }
        expect($workspace->files())->toBe(['app/Tools/Agents/SearchDocuments.php', 'app/Tools/SearchEverything.php', 'stubs/mod/@feature/Tools/tool.stub']);
    });
});

it('E13 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'type-first');
        $result = $w->artisan('mod:template', ['type' => 'tool'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@feature/Tools/tool', [
            'Starts as' => 'a class', 'Command' => 'mod:tool',
            'Writes' => 'app/Tools/<feature?>/<Name>.php', 'Try' => 'php artisan mod:tool Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@feature/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'))
            ->and($w->files())->toBe(['stubs/mod/@feature/Tools/tool.stub']);
    });
});
