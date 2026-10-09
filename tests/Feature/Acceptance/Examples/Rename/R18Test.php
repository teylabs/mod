<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Rename\Support\RenameScenario as S;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('R18 blocks unaccounted matching candidates', function () {
    Workspace::run(null, function (Workspace $w) {
        S::setup($w);
        $w->write('app/Modules/Inventory/Models/WidgetAudit.php', '<?php class WidgetAudit {}');
        S::commit($w);
        $data = S::preview($w);
        expect($data['would_write'])->toBeFalse()->and(implode(' ', array_column($data['warnings'], 'message')))->toContain('unaccounted candidate', 'WidgetAudit.php');
    });
});
