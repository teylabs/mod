<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Starters;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
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
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Using the configured base App\\Support\\Data\\DataTransferObject.  

   INFO  Links [app/Modules/Knowledge/Data/DocumentLinks.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Data/DocumentLinks.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E3.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Knowledge/Data/DocumentLinks.php', 'stubs/mod/@module/Data/links.stub']);
    });
});

it('E3 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'modules');
        $result = $w->artisan('mod:template', ['type' => 'dto', 'path' => '@module/Data/links'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Data/links', [
            'Starts as' => 'a DTO (extends the DataTransferObject base)', 'Command' => 'mod:links',
            'Writes' => 'app/Modules/<module>/Data/<Name>.php', 'Try' => 'php artisan mod:links Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Data/links.stub')))->toBe(CreationScenario::fixture('dto'))
            ->and($w->files())->toBe(['stubs/mod/@module/Data/links.stub']);
    });
});

it('E3 generates exactly the same links from a template refinement and a PHP declaration', function (string $form) {
    Workspace::run(null, function (Workspace $w) use ($form) {
        CreationScenario::setup($w);
        config()->set('mod.bases.links', 'App\\Support\\Data\\DataTransferObject');
        $layout = Mod::layout('modules');
        if ($form === 'php') {
            $layout->generates('links', in: 'Modules/{module}/Data', suffix: 'Links', stub: Starters::dto());
        } else {
            $w->artisan('mod:template', ['type' => 'dto', 'path' => '@module/Data/links'])->assertSuccessful();
            $next = new LayoutRegistry;
            $next->layout('modules')->generates('links', suffix: 'Links');
            app()->instance(LayoutRegistry::class, $next);
            CreationScenario::rebootConsole();
        }
        $w->artisan('mod:links', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Data/DocumentLinks.php')))->toBe(str_replace(['{{ namespace }}', '{{ baseImport }}', '{{ class }}', '{{ extends }}'], ['App\\Modules\\Knowledge\\Data', "\nuse App\\Support\\Data\\DataTransferObject;\n", 'DocumentLinks', ' extends DataTransferObject'], CreationScenario::fixture('dto')));
    });
})->with(['template', 'php']);
