<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('S11 generates template members, sibling aliases and slot options', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('stubs/mod/@module/Tools/[source]/tool.stub', "<?php\n\nnamespace {{ namespace }};\n\nuse {{ prompt.fqcn }};\n\nclass {{ class }}\n{\n    public string \$source = '{{ source }}';\n}\n");
        $w->write('stubs/mod/@module/Prompts/prompt.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n");
        Mod::scaffold('capability', fn (Scaffold $s) => $s->makes('tool', name: '{name}')->makes('prompt', name: '{name}Prompt'));
        $w->artisan('mod:capability', ['name' => 'Agents:SearchDocuments', '--source' => 'Notion'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Agents/Tools/Notion/SearchDocuments.php')))->toBe("<?php\n\nnamespace App\\Modules\\Agents\\Tools\\Notion;\n\nuse App\\Modules\\Agents\\Prompts\\SearchDocumentsPrompt;\n\nclass SearchDocuments\n{\n    public string \$source = 'Notion';\n}\n")
            ->and($w->exists('app/Modules/Agents/Prompts/SearchDocumentsPrompt.php'))->toBeTrue();
    });
})->skip('needs lane 4');
