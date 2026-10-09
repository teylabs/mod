<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S3 refuses a missing variant without writing non-interactively', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($result->normalisedOutput())->toBe("\n   ERROR  The crud scaffold uses stubs/mod.controller.crud.stub, which doesn't exist. Nothing was written.  \n\n   ERROR  Create stubs/mod.controller.crud.stub (start from the controller stub), or remove stub: 'crud' from the controller member.  \n\n")
            ->and($w->files())->toBe(['app/Http/Controllers/Controller.php', 'stubs/mod.request.crud.stub']);
    });
});

it('S3 offers to publish the missing variant then continues', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        $this->artisan('mod:crud', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation("The crud scaffold uses stubs/mod.controller.crud.stub, which doesn't exist. Create it from the controller stub?", 'yes')
            ->expectsOutputToContain('Published stub [stubs/mod.controller.crud.stub] from the controller stub. Edit it to make it the house controller.')
            ->expectsConfirmation('Write these 8 files?', 'yes')->assertSuccessful();
        foreach (Examples::paths() as $path) {
            expect($w->exists($path))->toBeTrue();
        }
    });
});
