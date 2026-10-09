<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\CreationScenario;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M4 skips a hand-made template that clashes with a file type', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/Models/model.stub', TemplateScenario::CLASS_STUB);
        $result = $workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe(TemplateScenario::normalise($workspace, "\n   WARN  Skipped template [stubs/mod/@module/Models/model.stub]: mod:model already exists. To change what models start as, use stubs/mod.model.stub.  \n\n\n   INFO  Model [app/Modules/Knowledge/Models/Document.php] created successfully.  \n\n"))
            ->and(TemplateScenario::normalise($workspace, $workspace->read('app/Modules/Knowledge/Models/Document.php')))
            ->toBe(TemplateScenario::normalise($workspace, "<?php\n\nnamespace App\\Modules\\Knowledge\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Document extends Model\n{\n    //\n}\n"));
    });
});

it('M4 names both ways forward without a terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        CreationScenario::setup($w);
        $r = $w->artisan('mod:template', ['type' => 'job'])->assertFailed();
        expect($r->normalisedOutput())->toBe(CreationScenario::errors(["mod:job already exists: it is the layout's job file type.", 'To customize what mod:job starts as, publish its stub: stubs/mod.job.stub.', 'To start a new template from the job stub, name it: mod:template job <name>.']))
            ->and($w->files())->toBe([]);
        $r = $w->artisan('mod:template', ['type' => 'list'])->assertFailed();
        expect($r->normalisedOutput())->toBe(CreationScenario::error("mod:list is one of mod's own commands. Choose another name, such as mod:template lister."));
    });
});

it('M4 publishes the job stub or asks for a reserved command replacement', function (string $name) {
    Workspace::run(null, function (Workspace $w) use ($name) {
        CreationScenario::setup($w);
        $cmd = TemplateScenario::testCase()->artisan('mod:template', ['type' => $name]);
        if ($name === 'job') {
            $cmd->expectsConfirmation('mod:job already exists. Customize its stub instead?', 'yes')
                ->expectsOutputToContain('Published stub [stubs/mod.job.stub]. mod:job now starts from it.')->assertSuccessful()->run();
            expect(str_replace("\r\n", "\n", $w->read('stubs/mod.job.stub')))->toBe(CreationScenario::fixture('job_template'));
        } else {
            $cmd->expectsQuestion("mod:list is one of mod's own commands. What should the template be called?", 'lister')->assertSuccessful()->run();
            expect(str_replace("\r\n", "\n", $w->read('stubs/mod/@module/Lists/lister.stub')))->toBe(CreationScenario::fixture('class_template'));
        }
    });
})->with(['job', 'list']);
