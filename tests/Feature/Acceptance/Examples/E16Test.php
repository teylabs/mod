<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E16 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->write('stubs/mod/@module/Tools/tool.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E16.stub'));
        mkdir($workspace->root->path('app/Modules/Agents/Tools'), 0700, true);
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe('
   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  

')
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Agents/Tools/SearchDocuments.php')))->toBe(str_replace("\r\n", "\n", (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E16.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Agents/Tools/SearchDocuments.php', 'stubs/mod/@module/Tools/tool.stub']);
    });
});
