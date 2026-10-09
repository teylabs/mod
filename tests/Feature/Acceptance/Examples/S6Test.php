<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S6 places every member across the ddd roots', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, 'ddd');
        mkdir($w->root->path('src/Domain/Knowledge'), 0700, true);
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($result->normalisedOutput())->toStartWith(Examples::plan(Examples::paths('src/Domain/Knowledge')))
            ->and(str_replace("\r\n", "\n", $w->read(Examples::paths('src/Domain/Knowledge')[7])))->toBe(Examples::controller('Domain\\Knowledge'));
        foreach (Examples::paths('src/Domain/Knowledge') as $path) {
            expect($w->exists($path))->toBeTrue($path);
        }
    });
});
