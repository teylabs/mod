<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E3 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')->generates('links', suffix: 'Links');
        config()->set('mod.bases.links', 'App\\Support\\Data\\DataTransferObject');
        $workspace->write('stubs/mod/@module/Data/links.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E3.stub'));
        mkdir($workspace->root->path('app/Modules/Knowledge/Data'), 0700, true);
        $result = $workspace->artisan('mod:links', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe('
   INFO  Using the configured base App\\Support\\Data\\DataTransferObject.  

   INFO  Links [app/Modules/Knowledge/Data/DocumentLinks.php] created successfully.  

')
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Knowledge/Data/DocumentLinks.php')))->toBe(str_replace("\r\n", "\n", (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E3.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Data/DocumentLinks.php', 'stubs/mod/@module/Data/links.stub']);
    });
});
