<?php

use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Feature\Scaffolds\Support\Examples;

const COLUMNS = ['key' => 'string', 'label' => 'string', 'sortable' => 'bool'];

/** @param list<array<string, mixed>>|null $default */
function recordsScaffold(?array $default = null): void
{
    Mod::scaffold('table', fn (Scaffold $s) => $s
        ->asks('columns', type: 'records', shape: COLUMNS, default: $default, label: 'Column')
        ->makes('job'));
}

function recordsStub(Workspace $w): void
{
    $w->write('stubs/mod.job.stub', "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    public const COLUMNS = {{ columns.json }};\n}\n");
}

it('accepts records as JSON and normalizes them to the declared field order', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold();
        recordsStub($w);
        $w->artisan('mod:table', ['name' => 'Knowledge:Document', '--columns' => '[{"sortable":true,"label":"Name","key":"name"},{"key":"company","label":"Company","sortable":false}]'])->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain('COLUMNS = [{"key":"name","label":"Name","sortable":true},{"key":"company","label":"Company","sortable":false}];');
    });
});

it('uses the recipe default without a flag or terminal', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold([['key' => 'name', 'label' => 'Name', 'sortable' => true]]);
        recordsStub($w);
        $w->artisan('mod:table', ['name' => 'Knowledge:Document'])->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain('COLUMNS = [{"key":"name","label":"Name","sortable":true}];');
    });
});

it('accepts an explicit empty list and prints nothing for it', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold([['key' => 'name', 'label' => 'Name', 'sortable' => true]]);
        recordsStub($w);
        $w->artisan('mod:table', ['name' => 'Knowledge:Document', '--columns' => '[]'])->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain('COLUMNS = [];');
    });
});

it('asks for the JSON flag when there is no default', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold();
        $result = $w->artisan('mod:table', ['name' => 'Knowledge:Document'])->assertFailed();
        expect($result->normalisedOutput())->toContain('mod:table needs a columns. Pass --columns=<json>.');
        expect($w->exists('app/Modules/Knowledge/Jobs/Document.php'))->toBeFalse();
    });
});

it('prompts for each record and field, offering the default records first', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold([['key' => 'name', 'label' => 'Name', 'sortable' => true]]);
        recordsStub($w);
        Examples::testCase()->artisan('mod:table', ['name' => 'Knowledge:Document'])
            ->expectsQuestion('Column 1: Key', 'name')->expectsQuestion('Column 1: Label', 'Name')->expectsConfirmation('Column 1: Sortable', 'yes')
            ->expectsConfirmation('Add another Column record?', 'yes')
            ->expectsQuestion('Column 2: Key', 'company')->expectsQuestion('Column 2: Label', 'Company')->expectsConfirmation('Column 2: Sortable', 'no')
            ->expectsConfirmation('Add another Column record?', 'no')
            ->expectsConfirmation('Write these 1 files?', 'yes')->assertSuccessful();
        expect($w->read('app/Modules/Knowledge/Jobs/Document.php'))->toContain('[{"key":"name","label":"Name","sortable":true},{"key":"company","label":"Company","sortable":false}]');
    });
});

it('refuses invalid records before anything is planned', function (string $json, string $message) {
    Workspace::run(null, function (Workspace $w) use ($json, $message) {
        config()->set('mod.layout', 'modules');
        recordsScaffold();
        $before = $w->files();
        $result = $w->artisan('mod:table', ['name' => 'Knowledge:Document', '--columns' => $json])->assertFailed();
        expect($result->normalisedOutput())->toContain($message)->and($w->files())->toBe($before);
    });
})->with([
    'not JSON' => ['name,label', 'mod:table: --columns must be a JSON list of records, such as [{"key":"key","label":"label","sortable":true}].'],
    'an object' => ['{"key":"name"}', '--columns must be a list of records'],
    'a scalar record' => ['["name"]', '--columns record 1 must be an object with key, label, sortable.'],
    'a missing field' => ['[{"key":"name","label":"Name"}]', '--columns record 1 needs sortable.'],
    'an unknown field' => ['[{"key":"name","label":"Name","sortable":true,"width":3}]', '--columns record 1 has unknown field width. Declared fields: key, label, sortable.'],
    'a wrong bool' => ['[{"key":"name","label":"Name","sortable":"yes"}]', '--columns record 1: sortable must be true or false.'],
    'an empty string' => ['[{"key":"name","label":"","sortable":true}]', '--columns record 1: label must be a non-empty string.'],
    'a later record' => ['[{"key":"a","label":"A","sortable":true},{"key":5,"label":"B","sortable":true}]', '--columns record 2: key must be a non-empty string.'],
]);

it('checks whole-number fields', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::scaffold('sizes', fn (Scaffold $s) => $s->asks('sizes', type: 'records', shape: ['name' => 'string', 'width' => 'int'])->makes('job'));
        $w->artisan('mod:sizes', ['name' => 'Knowledge:Document', '--sizes' => '[{"name":"a","width":"3"}]'])->assertFailed();
        $w->artisan('mod:sizes', ['name' => 'Knowledge:Document', '--sizes' => '[{"name":"a","width":3}]'])->assertSuccessful();
    });
});

it('rejects bad declarations when the recipe is written', function (Closure $declare, string $message) {
    expect($declare)->toThrow(GenerationRefused::class, $message);
})->with([
    'no shape' => [fn () => (new Scaffold)->asks('columns', type: 'records'), 'needs a shape'],
    'a shape on text' => [fn () => (new Scaffold)->asks('columns', shape: COLUMNS), 'only records questions have one'],
    'an unknown field type' => [fn () => (new Scaffold)->asks('columns', type: 'records', shape: ['key' => 'date']), 'has type [date]. Use string, bool or int.'],
    'a dotted field' => [fn () => (new Scaffold)->asks('columns', type: 'records', shape: ['a.b' => 'string']), 'must be a plain name'],
    'an invalid default' => [fn () => (new Scaffold)->asks('columns', type: 'records', shape: COLUMNS, default: [['key' => 'a']]), 'default record 1 needs label.'],
]);

it('reports invalid records as a blocking warning in a dry run', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        recordsScaffold();
        $plan = json_decode($w->artisan('mod:table', ['name' => 'Knowledge:Document', '--columns' => '[{"key":"a"}]', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);

        expect($plan['would_write'])->toBeFalse()->and($plan['warnings'][0]['message'])->toBe('mod:table: --columns record 1 needs label.')->and($plan['files'])->toBe([]);
    });
});
