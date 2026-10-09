<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M4 skips a hand-made template that clashes with a file type', function () {
    Workspace::run(null, function (Workspace $workspace) {
        TemplateScenario::tool($workspace);
        $workspace->write('stubs/mod/@module/Models/model.stub', TemplateScenario::CLASS_STUB);
        $result = $workspace->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($result->normalisedOutput())->toBe("\n   WARN  Skipped template [stubs/mod/@module/Models/model.stub]: mod:model already exists. To change what models start as, use stubs/mod.model.stub.  \n\n\n   INFO  Model [app/Modules/Knowledge/Models/Document.php] created successfully.  \n\n")
            ->and(str_replace("\r\n", "\n", $workspace->read('app/Modules/Knowledge/Models/Document.php')))
            ->toBe("<?php\n\nnamespace App\\Modules\\Knowledge\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Document extends Model\n{\n    //\n}\n");
    });
});
