<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S4 includes a snapshot and replaces the controller', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        Mod::scaffold('api', fn (Scaffold $s) => $s->include('crud')->makes('controller', name: 'Api{name}Controller', stub: 'api'));
        $w->write('stubs/mod.controller.api.stub', (string) file_get_contents(__DIR__.'/../../Scaffolds/Support/controller.stub'));
        $result = $w->artisan('mod:api', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $paths = Examples::paths();
        $paths[7] = 'app/Modules/Knowledge/Http/Controllers/ApiDocumentController.php';
        expect($result->normalisedOutput())->toStartWith(Examples::plan($paths, 'api'))
            ->and($w->exists('app/Modules/Knowledge/Http/Controllers/DocumentController.php'))->toBeFalse()
            ->and(str_replace("\r\n", "\n", $w->read($paths[7])))->toBe(str_replace('class DocumentController', 'class ApiDocumentController', Examples::controller()));
    });
});
