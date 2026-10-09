<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Rename\Contributors;
use Tey\Mod\Rename\Frontend\Frontend;
use Tey\Mod\Rename\Frontend\Runner;
use Tey\Mod\Rename\Frontend\RunResult;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R11 preserves unavailable frontend bytes with original lines and intended alias targets', function (string $extension) {
    Workspace::run(null, function (Workspace $w) use ($extension) {
        S::setup($w);
        Mod::scaffold('page-only', fn (Scaffold $s) => $s->makes('page', name: '{name}/Show'));
        $file = 'app/Modules/Inventory/resources/js/pages/Widget/Show.vue';
        $source = "<script setup lang=\"ts\">\r\nimport Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\r\nconst label = 'Inventory::Widget/Show';\r\n</script>";
        $source = str_replace('Show.vue', 'Show'.$extension, $source);
        $w->write($file, $source);
        app(Contributors::class)->set('frontend', new Frontend(new class implements Runner
        {
            public function run(string $basePath, string $input): RunResult
            {
                return new RunResult(null, 'Node parsing is unavailable.');
            }
        }));
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'page-only']);
        expect($data['warnings'])->toBe([])->and($data['moves'])->toHaveCount(1)->and($data['rewrites'])->toBe([]);
        $row = array_values(array_filter($data['checklist'], static fn (array $row): bool => $row['line'] === 2))[0];
        expect($row['file'])->toBe($file)->and($row['after_file'])->toBe(str_replace('Widget', 'Gadget', $file))->and($row['category'])->toBe('frontend-toolchain')->and($row['suggestion'])->toBe('@modules/Inventory/resources/js/pages/Gadget/Show'.$extension);
    });
})->with(['.vue', '']);

it('R11 maps Blade directive and anonymous component identities without touching PHP expressions or comments', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('stubs/mod/@module/resources/views/show-view.blade.php.stub', '<p>Template</p>');
        Mod::scaffold('view-only', fn (Scaffold $s) => $s->makes('show-view', as: 'view', name: '{name.kebab}s/show'));
        $w->write('app/Modules/Inventory/resources/views/widgets/show.blade.php', '<p>Edited body</p>');
        $w->write('resources/views/consumer.blade.php', "@include('inventory::widgets.show')\n{{ 'inventory::widgets.show' }}\n{{-- @include('inventory::widgets.show') --}}\n@include(\$view)\n");
        app(Contributors::class)->set('frontend', new Frontend);
        S::commit($w);
        $data = S::preview($w, ['--scaffold' => 'view-only']);
        expect($data['warnings'])->toBe([])->and($data['rewrites'])->toHaveCount(1)->and($data['rewrites'][0]['before'])->toBe('inventory::widgets.show')->and($data['rewrites'][0]['after'])->toBe('inventory::gadgets.show')->and($data['rewrites'][0]['line'])->toBe(1);
        expect(in_array('identity-string', array_column($data['checklist'], 'category'), true))->toBeTrue();
        expect(array_values(array_filter($data['checklist'], static fn (array $row): bool => $row['category'] === 'blade-identity'))[0]['line'])->toBe(4);
    });
});
