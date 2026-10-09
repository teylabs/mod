<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

it('fills every alias form before sorting imports in a sibling template', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('forms', fn (Scaffold $s) => $s->makes('request', name: 'Store{name}Request', as: 'store')->makes('job'));
        $w->write('stubs/mod.job.stub', "<?php\n\nnamespace {{ namespace }};\n\nuse Zed\\Last;\nuse {{ store.fqcn }};\nuse Alpha\\First;\n\nclass {{ class }} {\n    // {{ store }} {{store.camel}} {{ store.snake }} {{ store.kebab }} {{ store.studly }} {{ store.plural }}\n}\n");
        $w->artisan('mod:forms', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Jobs/Document.php')))->toBe("<?php\n\nnamespace App\\Modules\\Knowledge\\Jobs;\n\nuse Alpha\\First;\nuse App\\Modules\\Knowledge\\Requests\\StoreDocumentRequest;\nuse Zed\\Last;\n\nclass Document {\n    // StoreDocumentRequest storeDocumentRequest store_document_request store-document-request StoreDocumentRequest StoreDocumentRequests\n}\n");
    });
});

it('uses the app variant before the package variant', function (bool $appVariant) {
    Workspace::run(null, function (Workspace $w) use ($appVariant) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('tasks', fn (Scaffold $s) => $s->makes('job', stub: 'house'));
        $w->write('vendor/acme/stubs/job.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { /* package */ }\n");
        Mod::stubs()->for('job.house', Stub::file($w->root->path('vendor/acme/stubs/job.stub')));
        if ($appVariant) {
            $w->write('stubs/mod.job.house.stub', "<?php\nnamespace {{ namespace }};\nclass {{ class }} { /* app */ }\n");
        }
        $w->artisan('mod:tasks', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain($appVariant ? '/* app */' : '/* package */');
    });
})->with([false, true]);

it('refuses a later member collision before writing the earlier model or its companions', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        $w->write(Examples::paths()[7], 'existing controller');
        $before = $w->files();
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe($before)->and($w->read(Examples::paths()[7]))->toBe('existing controller');
    });
});

it('keeps or overwrites related artifacts as well as explicit members', function (bool $force) {
    Workspace::run(null, function (Workspace $w) use ($force) {
        Examples::setup($w);
        $w->write(Examples::paths()[2], 'existing factory');
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document', $force ? '--force' : '--skip-existing' => true])->assertSuccessful();
        expect($w->read(Examples::paths()[2]))->{$force ? 'toContain' : 'toBe'}($force ? 'class DocumentFactory' : 'existing factory');
    });
})->with([false, true]);

it('rejects overlapping planned member paths even with force', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('bad', fn (Scaffold $s) => $s->makes('model', as: 'first')->makes('model', as: 'second'));
        $w->artisan('mod:bad', ['name' => 'Knowledge:Document', '--force' => true])->assertFailed();
        expect($w->files())->toBe([]);
    });
});

it('does not leave the scaffold scope attached to subsequent ordinary commands', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertFailed();
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->assertSuccessful();
        $w->artisan('mod:model', ['name' => 'Knowledge:Document'])->expectsOutputToContain('already exists');
    });
});

it('lists generated bases without writing them before the plan is accepted', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'ddd');
        Mod::scaffold('data-set', fn (Scaffold $s) => $s->makes('dto'));
        $this->artisan('mod:data-set', ['name' => 'Knowledge:Document'])
            ->expectsOutputToContain('src/Domain/Shared/Data/DataTransferObject.php')
            ->expectsConfirmation('Write these 2 files?', 'no')->assertSuccessful();
        expect($w->files())->toBe([]);
    });
});

it('plans recursively generated companions before any member is written', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('resource-set', fn (Scaffold $s) => $s->makes('model', options: ['--resource', '--requests']));
        $w->write('app/Modules/Knowledge/Requests/StoreDocumentRequest.php', 'existing request');
        $w->artisan('mod:resource-set', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe(['app/Modules/Knowledge/Requests/StoreDocumentRequest.php']);
    });
});
