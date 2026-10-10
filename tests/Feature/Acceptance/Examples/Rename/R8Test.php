<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R8 refuses missing sources occupied destinations and canonical no-ops without writes', function (string $case, string $message) {
    Workspace::run(null, function (Workspace $w) use ($case, $message) {
        S::setup($w);
        $options = [];
        if ($case === 'missing') {
            unlink($w->root->path('app/Modules/Inventory/Models/Widget.php'));
        } elseif ($case === 'occupied') {
            $w->write('app/Modules/Inventory/Models/Gadget.php', '<?php class Gadget {}');
        } else {
            $options['new'] = 'Inventory:widget';
        }
        S::commit($w);
        $data = S::preview($w, $options);
        expect($data['would_write'])->toBeFalse()->and(array_column($data['warnings'], 'message'))->toContain($message);
        S::apply($w, $options, false);
    });
})->with([
    ['missing', 'mod:rename source app/Modules/Inventory/Models/Widget.php is missing. Restore it or choose a recipe matching the cluster. Nothing was written.'],
    ['occupied', 'mod:rename destination app/Modules/Inventory/Models/Gadget.php already exists. Choose another target. Nothing was written.'],
    ['noop', 'mod:rename source and target resolve to the same identity. Choose a different name. Nothing was written.'],
]);
