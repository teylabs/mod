<?php

use Tey\Mod\Facades\Mod;
use Tey\Mod\Scaffolds\Part;
use Tey\Mod\Scaffolds\Scaffold;
use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R4 requires the full eight-member grown tree and never replays its inserts', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        Mod::scaffold('resource-tabs', fn (Scaffold $s) => $s->asks('tabs', type: 'list', default: ['Overview'])
            ->makes('view-model', name: 'Manage{name}ViewModel', as: 'base')
            ->makes('controller')->each('tabs', 'tab')->part('tab', configure: fn (Part $p) => $p
            ->makes('view-model', name: '{name}{tab}ViewModel', as: 'view-model')->makes('page', name: '{name}/{tab}', as: 'page')
            ->inserts('base', 'tabs', 'entry')->inserts('controller', 'actions', 'action')));
        $w->write('app/Modules/Inventory/ViewModels/ManageWidgetViewModel.php', "<?php\nnamespace App\\Modules\\Inventory\\ViewModels;\nclass ManageWidgetViewModel { /* edited // mod:tabs */ }\n");
        $w->write('app/Modules/Inventory/Http/Controllers/WidgetController.php', "<?php\nnamespace App\\Modules\\Inventory\\Http\\Controllers;\nclass WidgetController { /* inserted Overview Details Notes // mod:actions */ }\n");
        foreach (['Overview', 'Details', 'Notes'] as $tab) {
            $w->write('app/Modules/Inventory/ViewModels/Widget'.$tab.'ViewModel.php', "<?php\nnamespace App\\Modules\\Inventory\\ViewModels;\nclass Widget{$tab}ViewModel {}\n");
            $w->write('app/Modules/Inventory/resources/js/pages/Widget/'.$tab.'.vue', '<template>Edited '.$tab.'</template>');
        }
        S::commit($w);
        expect(S::preview($w, ['--scaffold' => 'resource-tabs'])['would_write'])->toBeFalse();
        $partial = S::preview($w, ['--scaffold' => 'resource-tabs', '--tabs' => ['Overview', 'Details']]);
        expect($partial['would_write'])->toBeFalse();
        $data = S::preview($w, ['--scaffold' => 'resource-tabs', '--tabs' => ['Overview', 'Details', 'Notes']]);
        expect($data['warnings'])->toBe([])->and($data['would_write'])->toBeTrue()->and(count($data['moves']))->toBe(8)
            ->and($data['selection']['answers'])->toBe(['tabs' => ['Overview', 'Details', 'Notes']])->and($data['inserts'])->toBe([])
            ->and(array_column($data['moves'], 'to'))->toContain('app/Modules/Inventory/ViewModels/GadgetNotesViewModel.php', 'app/Modules/Inventory/resources/js/pages/Gadget/Notes.vue');
        S::apply($w, ['--scaffold' => 'resource-tabs', '--tabs' => ['Overview', 'Details', 'Notes']]);
    });
});
