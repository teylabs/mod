<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Mod\Templates\TemplateCatalog;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('M5 skips braces and group brackets and exposes the skip list', function (string $group) {
    Workspace::run(null, function (Workspace $workspace) use ($group) {
        TemplateScenario::tool($workspace);
        $path = 'stubs/mod/Modules/'.$group.'/Bad/broken.stub';
        $workspace->write($path, TemplateScenario::CLASS_STUB);
        $result = $workspace->artisan('mod:tool', ['name' => 'Agents:SearchDocuments'])->assertSuccessful();
        $fix = "template folders use @module; {$group} is the Layout API's form.";
        expect($result->normalisedOutput())->toBe("\n   WARN  Skipped template [{$path}]: {$fix}  \n\n\n   INFO  Tool [app/Modules/Agents/Tools/SearchDocuments.php] created successfully.  \n\n")
            ->and(Artisan::all())->not->toHaveKey('mod:broken')
            ->and(app(TemplateCatalog::class)->skipped())->toBe([$path => $fix]);
    });
})->with(['{module}', '[module]']);
