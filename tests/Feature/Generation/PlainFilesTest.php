<?php

use Tey\Mod\Facades\Mod;
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

it('keeps class roots namespaced when a plain template shares their folder', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/Models/card.vue.stub', '<template />');
        $w->artisan('mod:card', ['name' => 'Inventory:Card'])->assertSuccessful();
        $w->artisan('mod:model', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/Models/Widget.php'))->toContain('namespace App\\Modules\\Inventory\\Models;');
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

it('keeps the page file byte-for-byte when overwrite confirmation is declined', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('app/Modules/Inventory/resources/js/pages/Index.vue', '<template>Existing</template>');
        TemplateScenario::testCase()->artisan('mod:page', ['name' => 'Inventory:Index'])
            ->expectsConfirmation('app/Modules/Inventory/resources/js/pages/Index.vue already exists. Overwrite it?', 'no')->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/js/pages/Index.vue'))->toBe('<template>Existing</template>');
    });
});

it('stages missing tree page variants until the complete plan is accepted', function (bool $accept) {
    Workspace::run(null, function (Workspace $w) use ($accept) {
        Frontend::setup($w);
        Mod::scaffold('tree-page', fn (Scaffold $s) => $s->makes('page', stub: 'tree')->part('tab'));
        TemplateScenario::testCase()->artisan('mod:tree-page', ['name' => 'Inventory:Dashboard'])
            ->expectsConfirmation("The tree-page scaffold uses stubs/mod.page.tree.vue.stub, which doesn't exist. Create it from the page stub?", 'yes')
            ->expectsConfirmation('Write these 1 files?', $accept ? 'yes' : 'no')->assertSuccessful();
        expect($w->exists('stubs/mod.page.tree.vue.stub'))->toBe($accept)
            ->and($w->exists('app/Modules/Inventory/resources/js/pages/Dashboard.vue'))->toBe($accept);
    });
})->with([true, false]);

it('names the missing tree stack variant without writing in noninteractive mode', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w, 'react');
        Mod::scaffold('tree-page', fn (Scaffold $s) => $s->makes('page', stub: 'tree')->part('tab'));
        $before = $w->files();
        $w->artisan('mod:tree-page', ['name' => 'Inventory:Dashboard'])->assertFailed()->expectsOutputToContain('stubs/mod.page.tree.tsx.stub');
        expect($w->files())->toBe($before);
    });
});

it('asks for a missing plain template slot and uses it as a folder and value', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/prompts/[topic]/prompt.md.stub', '{{ topic }}: {{ name.headline }}');
        TemplateScenario::testCase()->artisan('mod:prompt', ['name' => 'Inventory:AnswerQuestion'])
            ->expectsQuestion('Which topic?', 'Widgets')->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/prompts/Widgets/answer-question.md'))->toBe('Widgets: Answer Question');
    });
});

it('refuses a missing plain-file insert anchor before writing any member and preserves CRLF', function (bool $missing) {
    Workspace::run(null, function (Workspace $w) use ($missing) {
        Frontend::setup($w);
        $source = "<script setup>\r\n".($missing ? '' : "// mod:imports\r\n")."</script>\r\n<template>\r\n<!-- mod:cards -->\r\n</template>\r\n";
        $w->write('app/Modules/Inventory/resources/js/pages/Dashboard.vue', $source);
        $w->write('stubs/mod/@module/resources/js/components/card.vue.stub', '<template><p>{{ count }}</p></template>');
        $w->write('stubs/mod.insert.card-import.stub', "import {{ widget.card }} from '{{ widget.card.import }}';");
        Mod::scaffold('dashboard-card-member', fn (Scaffold $s) => $s->makes('card', name: '{widget}Card'));
        Mod::scaffold('dashboard-cards', fn (Scaffold $s) => $s->part('widget', uses: 'dashboard-card-member', configure: fn ($part) => $part->inserts(into: '@module/resources/js/pages/Dashboard.vue', at: 'imports', stub: 'card-import')));
        $before = $w->files();
        $result = $w->artisan('mod:dashboard-cards.widget', ['name' => 'Inventory:Dashboard', 'value' => 'LowStock']);
        if ($missing) {
            $result->assertFailed();
            expect($w->files())->toBe($before)->and($w->read('app/Modules/Inventory/resources/js/pages/Dashboard.vue'))->toBe($source);
        } else {
            $result->assertSuccessful();
            $written = $w->read('app/Modules/Inventory/resources/js/pages/Dashboard.vue');
            expect($written)->toContain("LowStockCard.vue';\r\n// mod:imports\r\n")
                ->and(str_replace("\r\n", '', $written))->not->toContain("\n");
        }
    });
})->with([true, false]);

it('reports Blade source mentions without replacing source bytes', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $path = 'app/Modules/Inventory/resources/views/mail/widget-restocked.blade.php';
        $source = 'Inventory inventory WidgetRestocked widget-restocked widget_restocked WIDGETRESTOCKED {{ $slot }}';
        $w->write($path, $source);
        $args = ['--from' => $path, '--into' => '@module/resources/views/mail/mail-template'];
        $preview = json_decode($w->artisan('mod:template', [...$args, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($preview['mentions'], 'name'))->toContain('Inventory', 'inventory', 'widget-restocked', 'widget_restocked');
        $w->artisan('mod:template', $args)->assertSuccessful();
        expect($w->read('stubs/mod/@module/resources/views/mail/mail-template.blade.php.stub'))->toBe($source);
    });
});

it('uses the app alias for mirrored components and the group alias for module components', function (bool $mirrored) {
    Workspace::run(null, function (Workspace $w) use ($mirrored) {
        Frontend::setup($w);
        if ($mirrored) {
            Mod::layout('modules')->frontend(pages: 'resources/js/pages/{module}', components: 'resources/js/components/{module}', pageName: '{module}/{path}');
            $w->write('stubs/mod/resources/js/components/@module/layout.vue.stub', '<template><slot /></template>');
        } else {
            $w->write('stubs/mod/@module/resources/js/components/layout.vue.stub', '<template><slot /></template>');
        }
        $plan = json_decode($w->artisan('mod:layout', ['name' => 'Inventory:WidgetLayout', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['files'][0]['identity']['import'])->toBe($mirrored ? '@/components/Inventory/WidgetLayout.vue' : '@modules/Inventory/resources/js/components/WidgetLayout.vue');
    });
})->with([true, false]);

it('exposes Blade framework names and tags as sibling identities', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/views/mail/mail-view.blade.php.stub', '{{ $slot }}');
        $w->write('stubs/mod/@module/resources/views/components/badge.blade.php.stub', '{{ $slot }}');
        $w->write('stubs/mod/@module/resources/prompts/identities.md.stub', '{{ view.name }}|{{ badge.tag }}|{{ badge.path }}');
        Mod::scaffold('blade-identities', fn (Scaffold $s) => $s->makes('mail-view', name: 'WidgetRestocked', as: 'view')->makes('badge', name: 'StockBadge')->makes('identities', name: 'Reference'));
        $w->artisan('mod:blade-identities', ['name' => 'Inventory:Widget'])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/prompts/reference.md'))->toBe('inventory::mail.widget-restocked|x-inventory::stock-badge|app/Modules/Inventory/resources/views/components/stock-badge.blade.php');
    });
});

it('resolves file-question identities for an existing frontend file without its own generator template', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $path = 'app/Modules/Inventory/resources/js/components/WidgetLayout.vue';
        $w->write($path, '<template><slot /></template>');
        $w->write('stubs/mod/@module/resources/prompts/reference.md.stub', '{{ layout }}|{{ layout.import }}');
        Mod::scaffold('reference-file', fn (Scaffold $s) => $s->asks('layout', type: 'file')->makes('reference'));
        $w->artisan('mod:reference-file', ['name' => 'Inventory:Widget', '--layout' => $path])->assertSuccessful();
        expect($w->read('app/Modules/Inventory/resources/prompts/widget.md'))->toBe('WidgetLayout|@modules/Inventory/resources/js/components/WidgetLayout.vue');
    });
});
