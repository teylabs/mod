<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S1 plans and generates the eight CRUD files through their file types', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $paths = Examples::paths();
        $labels = ['Model', 'Migration', 'Factory', 'Request', 'Request', 'Resource', 'Policy', 'Controller'];
        $output = Examples::plan($paths);
        // Native model generation writes its factory before its migration.
        foreach ([0, 2, 1, 3, 4, 5, 6, 7] as $i) {
            $output .= "\n   INFO  {$labels[$i]} [{$paths[$i]}] created successfully.  \n";
        }
        expect($result->normalisedOutput())->toBe($output."\n")
            ->and(str_replace("\r\n", "\n", $w->read($paths[7])))->toBe(Examples::controller());
        foreach ($paths as $path) {
            expect($w->exists($path))->toBeTrue($path);
        }
        expect(count($w->files()))->toBe(10);
    });
});

it('S1 asks once before writing and cancellation writes nothing', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $this->artisan('mod:crud', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation('Write these 8 files?', false)->assertSuccessful();
        expect($w->files())->toBe(['stubs/mod.controller.crud.stub', 'stubs/mod.request.crud.stub']);
    });
});
