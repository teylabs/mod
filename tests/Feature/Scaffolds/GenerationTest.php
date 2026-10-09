<?php

use Tey\Mod\Commands\JobCommand;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\GenerationPlan;
use Tey\Mod\Generation\ModMigrationCreator;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Scaffolds\ScaffoldExecution;
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
        isolatedDomainNamespace();
        Mod::scaffold('data-set', fn (Scaffold $s) => $s->makes('dto'));
        Examples::testCase()->artisan('mod:data-set', ['name' => 'Knowledge:Document'])
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

it('uses the accepted migration timestamp after the native clock changes', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w);
        app()->bind(ModMigrationCreator::class, fn () => new class(app('files'), $w->root->path('stubs')) extends ModMigrationCreator
        {
            public function datePrefixFor(string $directory): string
            {
                $scope = app(ScaffoldExecution::class);

                return $scope->planning ? '2026_10_08_120000' : '2026_10_08_120100';
            }
        });
        $w->artisan('mod:crud', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->exists(Examples::paths()[1]))->toBeTrue()
            ->and($w->exists('app/Modules/Knowledge/Database/Migrations/2026_10_08_120100_create_documents_table.php'))->toBeFalse();
    });
});

it('accepts the same explicit placement options as the normal generators', function (array $arguments) {
    Workspace::run(null, function (Workspace $w) use ($arguments) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $w->artisan('mod:pair', $arguments)->assertSuccessful();
        expect($w->files())->toBe(['app/Modules/Knowledge/Jobs/Document.php', 'app/Modules/Knowledge/Models/Document.php']);
    });
})->with([
    [['name' => 'Document', '--module' => 'Knowledge']],
    [['name' => 'Document', '--in' => 'Knowledge']],
]);

it('publishes no variants when the complete plan is cancelled', function () {
    Workspace::run(null, function (Workspace $w) {
        Examples::setup($w, controller: false);
        Examples::testCase()->artisan('mod:crud', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation("The crud scaffold uses stubs/mod.controller.crud.stub, which doesn't exist. Create it from the controller stub?", 'yes')
            ->expectsConfirmation('Write these 8 files?', 'no')->assertSuccessful();
        expect($w->exists('stubs/mod.controller.crud.stub'))->toBeFalse()
            ->and($w->exists(Examples::paths()[0]))->toBeFalse();
    });
});

it('reports a new group once after the accepted plan starts writing', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $result = $w->artisan('mod:pair', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect(substr_count($result->normalisedOutput(), 'Created new module Knowledge.'))->toBe(1);
    });
});

it('refuses a missing ordinary template before any member is generated', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::stubs()->for('job', Stub::file($w->root->path('missing.stub')));
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        $w->artisan('mod:pair', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($w->files())->toBe([]);
    });
});

class ScaffoldHookJob extends JobCommand
{
    protected function afterGeneration(GenerationPlan $plan, int $exitCode): void
    {
        file_put_contents($this->getLaravel()->basePath('hook-ran'), (string) $exitCode);
    }
}

it('runs generator write hooks only after the scaffold plan is accepted', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::generators()->use('job', ScaffoldHookJob::class);
        Mod::scaffold('pair', fn (Scaffold $s) => $s->makes('model')->makes('job'));
        Examples::testCase()->artisan('mod:pair', ['name' => 'Knowledge:Document'])
            ->expectsConfirmation('Write these 2 files?', 'no')->assertSuccessful();
        expect($w->files())->toBe([]);
    });
});
