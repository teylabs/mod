<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\TemplateScenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('follows extension casing for nested file names and acronyms', function (string $extension, string $name, string $expected) {
    Workspace::run(null, function (Workspace $w) use ($extension, $name, $expected) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/example'.$extension.'.stub', '{{ name.studly }}');
        $w->artisan('mod:example', ['name' => 'Inventory:'.$name])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/resources/js/components/'.$expected.$extension))->toBeTrue();
    });
})->with([
    ['.vue', 'typedFolder/APIKey', 'typedFolder/APIKey'],
    ['.tsx', 'APIKeys/APIKey', 'api-keys/api-key'],
    ['.jsx', 'APIKeys/APIKey', 'api-keys/api-key'],
    ['.blade.php', 'Mail/WidgetRestocked', 'mail/widget-restocked'],
    ['.md', 'APIKeys/AnswerQuestion', 'api-keys/answer-question'],
    ['.css', 'APIKeys/MainStyle', 'api-keys/main-style'],
    ['.ts', 'Hooks/useIndexFilter', 'Hooks/useIndexFilter'],
    ['.js', 'Hooks/useIndexFilter', 'Hooks/useIndexFilter'],
]);

it('honours a file type case override', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/widget.vue.stub', '{{ name.studly }}');
        Mod::layout('modules')->generates('widget', case: 'kebab');
        $w->artisan('mod:widget', ['name' => 'Inventory:APIKey'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/resources/js/components/api-key.vue'))->toBeTrue();
    });
});

it('P2 generates a DDD page in the application root', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        config()->set('mod.layout', 'ddd');
        $result = $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect($w->exists('app/Modules/Inventory/resources/js/pages/Widget/Index.vue'))->toBeTrue()
            ->and($result->normalisedOutput())->toContain("Inertia::render('Inventory::Widget/Index')");
    });
});

it('P3 uses mirrored frontend folders and page identity patterns', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
        $plan = json_decode($w->artisan('mod:page', ['name' => 'Inventory:Widget/Index', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][0]['path'])->toBe('resources/js/pages/Inventory/Widget/Index.vue')
            ->and($plan['files'][0]['identity']['name'])->toBe('Inventory/Widget/Index');
        $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect($w->exists('resources/js/pages/Inventory/Widget/Index.vue'))->toBeTrue();
    });
});

it('uses the app page template before the minimal default', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod.page.vue.stub', '<template><h1>{{ name.studly }}</h1></template>');
        $w->artisan('mod:page', ['name' => 'Inventory:Widget/Index'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/js/pages/Widget/Index.vue'))->toBe('<template><h1>Index</h1></template>');
    });
});

it('generates valid minimal pages without TypeScript', function (string $stack, string $extension) {
    Workspace::run(null, function (Workspace $w) use ($stack, $extension) {
        Frontend::setup($w, $stack, false);
        $w->artisan('mod:page', ['name' => 'Inventory:Index'])->assertSuccessful();
        $source = $w->read('app/Modules/Inventory/resources/js/pages/'.($stack === 'react' ? 'index' : 'Index').$extension);
        expect($source)->not->toContain('lang="ts"');
    });
})->with([['vue', '.vue'], ['react', '.jsx']]);

it('explains a missing Inertia stack and offers no page in the inventory', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->artisan('mod:page', ['name' => 'Inventory:Index'])->assertFailed()->expectsOutputToContain('No Inertia app found in package.json');
        $list = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($list['types'], 'id'))->not->toContain('page')->and($w->files())->toBe([]);
    });
});

it('lists plain file metadata and its command derived from the first dot', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/views/components/card.blade.php.stub', '<p>{{ $slot }}</p>');
        $list = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        $card = array_values(array_filter($list['types'], fn (array $type) => $type['id'] === 'card'))[0];
        expect($card['plain'])->toBeTrue()->and($card['extension'])->toBe('.blade.php')->and($card['case'])->toBe('kebab');
    });
});

it('keeps PHP templates class-shaped and accepts app resources templates', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/Actions/notify.php.stub', TemplateScenario::CLASS_STUB);
        $w->write('stubs/mod/resources/prompts/guide.md.stub', '{{ name.headline }}');
        $w->artisan('mod:notify', ['name' => 'Inventory:NotifyWidget'])->assertSuccessful();
        $w->artisan('mod:guide', ['name' => 'AnswerQuestion'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Actions/NotifyWidget.php'))->toContain('class NotifyWidget')
            ->and($w->read('resources/prompts/answer-question.md'))->toBe('Answer Question');
    });
});

it('renders list JSON and explicit nested expressions without touching unknown expressions', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/options.vue.stub', '{{ options.json }}|{{ {{ name.camel }}.id }}|{{ count }}|@{{ name }}');
        Mod::scaffold('options-set', fn (Scaffold $s) => $s->asks('options', type: 'list', default: ['One', 'Two'])->makes('options'));
        $w->artisan('mod:options-set', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/js/components/Widget.vue'))->toBe('["One","Two"]|{{ widget.id }}|{{ count }}|{{ name }}');
    });
});

it('warns about bare known placeholders only for Vue and Blade', function (string $extension, int $warnings) {
    Workspace::run(null, function (Workspace $w) use ($extension, $warnings) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/example'.$extension.'.stub', "<!-- {{ name }} -->\r\n{{ name.studly }}\r\n@{{ name }}");
        $plan = json_decode($w->artisan('mod:example', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['warnings'])->toHaveCount($warnings)->and($plan['would_write'])->toBeTrue();
    });
})->with([['.vue', 1], ['.blade.php', 1], ['.tsx', 0], ['.md', 0]]);

it('refuses plain filename traversal before creating any files', function (string $name) {
    Workspace::run(null, function (Workspace $w) use ($name) {
        Frontend::setup($w);
        $before = $w->files();
        $w->artisan('mod:page', ['name' => 'Inventory:'.$name])->assertFailed();
        expect($w->files())->toBe($before);
    });
})->with(['../Escape', 'Folder/../../Escape', './Escape', '/Escape']);

it('keeps informational plan warnings non-blocking but blocks by default', function () {
    $plan = new Plan('mod:page');
    $plan->warning('Ambiguous placeholder', blocking: false);
    expect($plan->wouldWrite)->toBeTrue();
    $plan->warning('Missing answer');
    expect($plan->wouldWrite)->toBeFalse();
});

it('refuses a missing page variant before writing and names the stack-specific remedy', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w, 'react');
        $w->remove(['stubs/mod.page.crud-index.tsx.stub']);
        $before = $w->files();
        $w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget'])->assertFailed()->expectsOutputToContain('stubs/mod.page.crud-index.tsx.stub');
        expect($w->files())->toBe($before);
    });
});

it('asks to create a missing stack variant from the minimal page before confirming generation', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w, 'react');
        $w->remove(['stubs/mod.page.crud-index.tsx.stub']);
        TemplateScenario::testCase()->artisan('mod:crud-pages', ['name' => 'Inventory:Widget'])
            ->expectsConfirmation("The crud-pages scaffold uses stubs/mod.page.crud-index.tsx.stub, which doesn't exist. Create it from the page stub?", 'yes')
            ->expectsConfirmation('Write these 12 files?', 'yes')->assertSuccessful();
        expect($w->read('stubs/mod.page.crud-index.tsx.stub'))->toContain("import { Head } from '@inertiajs/react'");
    });
});
