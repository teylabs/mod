<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('E15 normalizes file names and resolves kebab dash-free camel and Pascal commands', function (string $file, string $command) {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) use ($file, $command) {
        config()->set('mod.layout', 'modules');
        $workspace->write('stubs/mod/@module/ViewModels/'.$file.'.stub', TemplateScenario::CLASS_STUB);
        mkdir($workspace->root->path('app/Modules/Agents/ViewModels'), 0700, true);
        $application = Artisan::all()['mod:show-document-page']->getApplication();
        expect($application)->not->toBeNull();
        $resolved = $application->find('mod:'.$command);
        expect($resolved->getName())->toBe('mod:show-document-page');
        $result = $workspace->artisan((string) $resolved->getName(), ['name' => 'Agents:ShowConversationPage'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   INFO  Show Document Page [app/Modules/Agents/ViewModels/ShowConversationPage.php] created successfully.  \n\n"))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/ViewModels/ShowConversationPage.php')))
            ->toBe(TemplateScenario::normalise($workspace, TemplateScenario::content('App\\Modules\\Agents\\ViewModels', 'ShowConversationPage')));
    });
})->with(['show-document-page', 'ShowDocumentPage', 'show_document_page'])->with(['show-document-page', 'showdocumentpage', 'showDocumentPage', 'ShowDocumentPage']);
