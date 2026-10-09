<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('S10 refuses the whole plan without a collision answer', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $path = Examples::paths()[0];
        $w->write($path, 'existing model');
        $result = $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($result->normalisedOutput())->toBe(Examples::plan(Examples::paths(), exists: [$path])."\n   ERROR  $path already exists.  \n\n   ERROR  Nothing was written. Pass --skip-existing to keep it and write the rest, or --force to overwrite it.  \n\n")
            ->and(count($w->files()))->toBe(3)->and($w->read($path))->toBe('existing model');
    });
});

it('S10 keeps existing members while generating their related files and aliases', function (bool $interactive) {
    Workspace::run(null, function (Workspace $w) use ($interactive) {
        Examples::setup($w);
        $w->write(Examples::paths()[0], 'existing model');
        if ($interactive) {
            $this->artisan('mod:crud', ['name' => 'Knowledge:Document'])
                ->expectsChoice('1 file already exists. What should happen?', 'Keep it, and write the other 7', ['Keep it, and write the other 7', 'Overwrite it', 'Cancel'])
                ->assertSuccessful();
        } else {
            $w->artisan('mod:crud', ['name' => 'Knowledge:Document', '--skip-existing' => true])->assertSuccessful();
        }
        expect($w->read(Examples::paths()[0]))->toBe('existing model')
            ->and(str_replace("\r\n", "\n", $w->read(Examples::paths()[7])))->toBe(Examples::controller());
        foreach (Examples::paths() as $path) {
            expect($w->exists($path))->toBeTrue($path);
        }
    });
})->with([false, true]);

it('S10 force overwrites and cancel writes nothing', function (bool $force) {
    Workspace::run(null, function (Workspace $w) use ($force) {
        Examples::setup($w);
        $w->write(Examples::paths()[0], 'existing model');
        if ($force) {
            $w->artisan('mod:crud', ['name' => 'Knowledge:Document', '--force' => true])->assertSuccessful();
            expect($w->read(Examples::paths()[0]))->toContain('class Document extends Model');
        } else {
            $this->artisan('mod:crud', ['name' => 'Knowledge:Document'])
                ->expectsChoice('1 file already exists. What should happen?', 'Cancel', ['Keep it, and write the other 7', 'Overwrite it', 'Cancel'])->assertSuccessful();
            expect(count($w->files()))->toBe(3);
        }
    });
})->with([false, true]);
