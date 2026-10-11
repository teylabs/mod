<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

function scaffoldStack(Workspace $w, string $extension): void
{
    $adapter = $extension === 'vue' ? '@inertiajs/vue3' : '@inertiajs/react';
    $w->write('package.json', json_encode(['dependencies' => [$adapter => '^2']]));
    if ($extension === 'tsx') {
        $w->write('tsconfig.json', '{}');
    }
}

function stackScaffold(bool $tree): void
{
    Mod::vendor('acme')->scaffold('pages', function (Scaffold $s) use ($tree) {
        $s->makes('page', name: '{name}/List', stub: 'list');
        if ($tree) {
            $s->part('detail', fn (Part $p) => $p->makes('page', name: '{name}/Detail', stub: 'list'));
        }
    });
}

it('chooses the planned extension after app overrides and before generic package variants', function (string $extension, bool $tree, string $winner) {
    Workspace::run(null, function (Workspace $w) use ($extension, $tree, $winner) {
        scaffoldStack($w, $extension);
        $w->write('vendor/acme/kit/stubs/generic.stub', 'generic');
        Mod::stubs()->forFileType('page.list', Stub::file($w->root->path('vendor/acme/kit/stubs/generic.stub')));
        if ($winner !== 'generic') {
            foreach (['vue', 'tsx', 'jsx'] as $variant) {
                $w->write('vendor/acme/kit/stubs/list.'.$variant.'.stub', 'package-'.$variant);
                Mod::stubs()->forFileType('page.list.'.$variant, Stub::file($w->root->path('vendor/acme/kit/stubs/list.'.$variant.'.stub')));
            }
        }
        if ($winner === 'app') {
            $w->write('stubs/mod.page.list.'.$extension.'.stub', 'app-'.$extension);
        }
        stackScaffold($tree);
        $command = 'mod:acme/pages';
        $leaf = 'List';
        $path = 'resources/js/pages/'.($extension === 'vue' ? 'Billing/'.$leaf : 'billing/'.strtolower($leaf)).'.'.$extension;
        $preview = json_decode($w->artisan($command, ['name' => 'Billing', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true);
        expect($preview['warnings'])->toBe([])->and($preview['files'][0]['path'])->toBe($path)->and($w->exists($path))->toBeFalse();
        $w->artisan($command, ['name' => 'Billing'])->assertSuccessful();
        $expected = $winner === 'generic' ? 'generic' : $winner.'-'.$extension;
        expect($w->read($path))->toBe($expected);
        if ($tree) {
            $w->artisan('mod:acme/pages.detail', ['name' => 'Billing', 'value' => 'Details'])->assertSuccessful();
            $detail = 'resources/js/pages/'.($extension === 'vue' ? 'Billing/Detail' : 'billing/detail').'.'.$extension;
            expect($w->read($detail))->toBe($expected);
        }
    });
})->with(['vue', 'tsx', 'jsx'])->with([false, true])->with(['package', 'app', 'generic']);

it('refuses another stacks variant and names the missing extension', function (bool $tree) {
    Workspace::run(null, function (Workspace $w) use ($tree) {
        scaffoldStack($w, 'tsx');
        $w->write('vendor/acme/kit/stubs/list.vue.stub', '<template>Vue</template>');
        Mod::stubs()->forFileType('page.list.vue', Stub::file($w->root->path('vendor/acme/kit/stubs/list.vue.stub')));
        stackScaffold($tree);
        $command = 'mod:acme/pages';
        $result = $w->artisan($command, ['name' => 'Billing']);
        $result->assertFailed()->expectsOutputToContain('mod.page.list.tsx.stub');
        expect($w->exists('resources/js/pages/billing/list.tsx'))->toBeFalse()->and($w->exists('resources/js/pages/billing/detail.tsx'))->toBeFalse();
    });
})->with([false, true]);
