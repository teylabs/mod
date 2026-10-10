<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('R18 blocks unaccounted matching candidates', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('app/Modules/Inventory/Models/WidgetAudit.php', '<?php class WidgetAudit {}');
        S::commit($w);
        $data = S::preview($w);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('unaccounted candidate', 'WidgetAudit.php');
        S::apply($w, success: false);
    });
});

it('R18 cancellation leaves a clean tree and retry applies a freshly built plan', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $index = file_get_contents($w->root->path('.git/index'));
        Examples::testCase()->artisan('mod:rename', ['old' => 'Inventory:Widget', 'new' => 'Inventory:Gadget', '--scaffold' => 'model-only'])
            ->expectsConfirmation('Rename this cluster and stage the changes?', 'no')->expectsOutput('Rename cancelled. Nothing was written.')->assertSuccessful();
        expect(file_get_contents($w->root->path('.git/index')))->toBe($index)->and($w->exists('.git/mod-rename/journal.json'))->toBeFalse()->and(S::git($w, ['status', '--porcelain']))->toBe('');
        S::apply($w);
    });
});

it('R18 refuses recipe drift that introduces an absent member', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('model-only', fn (Scaffold $s) => $s->makes('model')->makes('class', name: '{name}Extra', as: 'extra'));
        $data = S::preview($w);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('missing');
        S::apply($w, success: false);
    });
});
