<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\PathGuard;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('rejects external and escaping roots before contributor writes are possible', function (string $root) {
    Workspace::run(null, function (Workspace $w) use ($root) {
        S::setup($w);
        Mod::layout('outside')->extends('modules')->path($root.'/{area}');
        config()->set('mod.layout', 'outside');
        Mod::scaffold('unsafe-cluster', fn (Scaffold $s) => $s->makes('model'));
        $data = S::preview($w, ['--scaffold' => 'unsafe-cluster']);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('outside the project');
    });
})->with(['/tmp/mod-rename-outside']);

it('rejects case-ambiguous path segments independently of disk casing', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        expect((new PathGuard($w->root->path))->problem('app/modules/Inventory/Models/Gadget.php'))->toBe('mod:rename cannot safely perform this case-only path move on this filesystem. Choose a temporary distinct name first. Nothing was written.');
    });
});

it('rejects duplicate destinations and destinations occupied by another source', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('duplicate', fn (Scaffold $s) => $s->makes('model', as: 'first')->makes('model', as: 'second'));
        $data = S::preview($w, ['--scaffold' => 'duplicate']);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('duplicate destination');
        Mod::scaffold('occupied-source', fn (Scaffold $s) => $s->makes('model')->makes('model', name: 'Gadget', as: 'stable'));
        $w->write('app/Modules/Inventory/Models/Gadget.php', "<?php\nnamespace App\\Modules\\Inventory\\Models;\nclass Gadget {}\n");
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'occupied-source']);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('already exists');
    });
});

it('previews without a Git repository and reports the corrective blocker', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('model-only', fn (Scaffold $s) => $s->makes('model'));
        $data = json_decode($w->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['would_write'])->toBeFalse()->and($data['warnings'][0]['message'])->toBe('mod:rename requires a Git repository. Initialise and commit the project before retrying. Nothing was written.');
    });
});
