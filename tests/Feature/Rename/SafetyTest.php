<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\GitProbe;
use Tey\Mod\Rename\Planner;
use Tey\Mod\Rename\Request;
use Tey\Mod\Rename\Snapshot;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('includes root translation files in the immutable scan snapshot', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('lang/en/inventory.php', "<?php return ['title' => 'Widget'];\n");
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->wouldWrite)->toBeTrue()->and($result->inputs?->roots)->toContain('lang')
            ->and($result->inputs?->files)->toHaveKey('lang/en/inventory.php');
    });
});

it('reports every kind of dirty Git state but ignores build output', function (string $case) {
    Workspace::run(null, function (Workspace $w) use ($case) {
        S::setup($w);
        if ($case === 'tracked' || $case === 'staged') {
            $w->write('composer.json', $w->read('composer.json')."\n");
            if ($case === 'staged') {
                S::git($w, ['add', 'composer.json']);
            }
        } else {
            $w->write($case === 'ignored' ? 'build/output.txt' : 'notes.txt', 'Changed');
        }
        $data = S::preview($w);
        expect($data['would_write'])->toBe($case === 'ignored')->and(count($data['moves']))->toBe(1);
    });
})->with(['tracked', 'staged', 'untracked', 'ignored']);

it('snapshots original CRLF bytes and detects changed bytes permissions membership and templates', function (string $case) {
    Workspace::run(null, function (Workspace $w) use ($case) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $source = str_replace("\n", "\r\n", $w->read($path));
        $w->write($path, $source);
        $w->write('stubs/mod.model.stub', 'Original template');
        S::commit($w);
        $result = app(Planner::class)->build(new Request('Inventory:Widget', 'Inventory:Gadget', 'model-only'));
        expect($result->plan->wouldWrite)->toBeTrue()->and($result->bodies[$path])->toBe(str_replace('class Widget', 'class Gadget', $source))
            ->and($result->plan->rename['rewrites'][0]['line'])->toBe(3);
        $inputs = $result->inputs;
        expect($inputs)->not->toBeNull();
        if ($case === 'bytes') {
            $w->write($path, $source.'// edit');
        } elseif ($case === 'membership') {
            $w->write('tests/NewConsumer.php', '<?php // New');
        } elseif ($case === 'template') {
            $w->write('stubs/mod.model.stub', 'Changed template');
        } else {
            chmod($w->root->path($path), 0444);
            clearstatcache(true, $w->root->path($path));
        }
        try {
            expect((new Snapshot)->unchanged($inputs, $inputs->definitionHash))->toBeFalse();
        } finally {
            chmod($w->root->path($path), 0644);
        }
    });
})->with(['bytes', 'membership', 'template', 'mode']);

it('refuses declaration drift configured-group absence directories and symlink sources', function (string $case) {
    Workspace::run(null, function (Workspace $w) use ($case) {
        S::setup($w);
        $path = 'app/Modules/Inventory/Models/Widget.php';
        $options = [];
        if ($case === 'declaration') {
            $w->write($path, str_replace('class Widget', 'class StockItem', $w->read($path)));
        } elseif ($case === 'group') {
            $options['new'] = 'Missing:Gadget';
        } elseif ($case === 'directory') {
            mkdir($w->root->path('app/Modules/Inventory/Models/Gadget.php'));
            $w->write('app/Modules/Inventory/Models/Gadget.php/.gitkeep', '');
        } else {
            $w->write('app/RealWidget.php', $w->read($path));
            unlink($w->root->path($path));
            symlink($w->root->path('app/RealWidget.php'), $w->root->path($path));
        }
        S::commit($w);
        expect(S::preview($w, $options)['would_write'])->toBeFalse();
    });
})->with(['declaration', 'group', 'directory', 'symlink']);

it('reads linked worktree metadata without consulting another worktree dirt', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $linked = $w->root->path('linked');
        S::git($w, ['worktree', 'add', '--detach', $linked]);
        $w->write('composer.json', $w->read('composer.json')."\n");
        try {
            $state = (new GitProbe)->inspect($linked);
            expect($state->clean())->toBeTrue()->and($state->root)->toBe(str_replace('\\', '/', realpath($linked)))
                ->and($state->index)->not->toBe($linked.'/.git/index')->and(is_file($state->index))->toBeTrue();
        } finally {
            S::git($w, ['worktree', 'remove', $linked]);
        }
    });
});

it('blocks unsafe folder moves before changing any files or index entries', function (string $case) {
    Workspace::run(null, function (Workspace $w) use ($case) {
        S::setup($w);
        Mod::scaffold('folder-pages', fn (Scaffold $s) => $s->folder('pages', '{name}', as: 'pageFolder')->makes('page', name: '{name}/Index/Page', as: 'indexPage'));
        $from = 'app/Modules/Inventory/resources/js/pages/Widget';
        $w->write($from.'/Index/Page.vue', '<template>Original</template>');
        $options = ['--scaffold' => 'folder-pages'];
        if ($case === 'collision') {
            $w->write('app/Modules/Inventory/resources/js/pages/Gadget/.keep', 'Existing destination');
        } elseif ($case === 'case-only') {
            $options['new'] = 'Inventory:widget';
        } elseif ($case === 'symlink') {
            symlink($w->root->path('app/Modules/Catalog'), $w->root->path($from.'/Partials'));
        } elseif ($case === 'overlap') {
            Mod::scaffold('folder-pages', fn (Scaffold $s) => $s->folder('pages', '{name}', as: 'pageFolder')->folder('pages', '{name}/Index', as: 'nested'));
        } elseif ($case === 'kept') {
            Mod::scaffold('folder-pages', fn (Scaffold $s) => $s->folder('pages', '{name}', as: 'pageFolder')->makes('page', name: '{name}/Index/Page', existing: 'keep'));
        } elseif ($case === 'outside-destination') {
            $options['old'] = 'Inventory:Widgets';
            Mod::scaffold('folder-pages', fn (Scaffold $s) => $s->folder('pages', '{name.singular}', as: 'pageFolder')
                ->makes('page', name: '{name.singular}/Index/Page', as: 'indexPage')->makes('page', name: '{name}/Extra', as: 'outsidePage'));
            $w->write('app/Modules/Inventory/resources/js/pages/Widgets/Extra.vue', '<template>Outside</template>');
        } elseif ($case === 'parent-file') {
            $options['new'] = 'Inventory:Blocked/Gadget';
            $w->write('app/Modules/Inventory/resources/js/pages/Blocked', 'Not a directory');
        } elseif ($case === 'excluded-directory') {
            mkdir($w->root->path($from.'/node_modules'));
        }
        S::commit($w);
        if ($case === 'dirty') {
            $w->write($from.'/Index/Page.vue', '<template>Outside edit</template>');
        } elseif ($case === 'ignored') {
            $w->write('.gitignore', $w->read('.gitignore')."\n/{$from}/ignored.txt\n");
            S::commit($w);
            $w->write($from.'/ignored.txt', 'Ignored content');
        }
        expect(S::preview($w, $options)['would_write'])->toBeFalse();
        $original = $w->read($from.'/Index/Page.vue');
        S::apply($w, $options, success: false);
        expect($w->read($from.'/Index/Page.vue'))->toBe($original)->and($w->exists('.git/mod-rename/journal.json'))->toBeFalse();
    });
})->with(['collision', 'case-only', 'symlink', 'dirty', 'ignored', 'overlap', 'kept', 'outside-destination', 'parent-file', 'excluded-directory']);
