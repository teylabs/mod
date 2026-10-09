<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('generates E2 from the edited generator template', function () {
    putenv('COLUMNS=72');
    Workspace::run(null, function (Workspace $workspace) {
        config()->set('mod.layout', 'modules');

        $workspace->write('stubs/mod/@module/Tools/tool.stub', (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E2.stub'));
        mkdir($workspace->root->path('app/Modules/Agents/Tools'), 0700, true);
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, '
   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  

'))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Agents/Tools/SearchDocuments.php')))->toBe(TemplateScenario::normalise($workspace, (string) file_get_contents(__DIR__.'/../../../Fixtures/Templates/E2.php.txt')));
        expect($workspace->files())->toBe(['app/Modules/Agents/Tools/SearchDocuments.php', 'stubs/mod/@module/Tools/tool.stub']);
    });
});

it('E2 creates its generator template with exact output', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w, 'modules');
        $result = $w->artisan('mod:template', ['type' => 'tool'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(CreationScenario::output('@module/Tools/tool', [
            'Starts as' => 'a class', 'Command' => 'mod:tool',
            'Writes' => 'app/Modules/<module>/Tools/<Name>.php', 'Try' => 'php artisan mod:tool Agents:<Name>',
        ]))->and(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Tools/tool.stub')))->toBe(CreationScenario::fixture('class_template'))
            ->and($w->files())->toBe(['stubs/mod/@module/Tools/tool.stub']);
    });
});

it('E2 generates exactly the same tool from a created template and a PHP declaration', function (string $form) {
    Workspace::run(null, function (Workspace $w) use ($form) {
        CreationScenario::setup($w);
        if ($form === 'php') {
            $w->write('stubs/tool.stub', CreationScenario::fixture('class_template'));
            Mod::layout('modules')->generates('tool', in: 'Modules/{module}/Tools', stub: Stub::file($w->root->path('stubs/tool.stub')));
        } else {
            $w->artisan('mod:template', ['type' => 'tool'])->assertSuccessful();
            CreationScenario::rebootConsole();
        }
        $r = $w->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        expect($r->normalisedOutput())->toBe("\n   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  \n\n")
            ->and(str_replace("\r\n", "\n", $w->read('app/Modules/Agents/Tools/SearchDocuments.php')))->toBe(str_replace(['{{ namespace }}', '{{ baseImport }}', '{{ class }}', '{{ extends }}'], ['App\\Modules\\Agents\\Tools', '', 'SearchDocuments', ''], CreationScenario::fixture('class_template')));
    });
})->with(['template', 'php']);
