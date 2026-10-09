<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F6 generates a mailable and its qualified Markdown view', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $w->write('app/Modules/Inventory/Models/.gitkeep', '');
        $result = $w->artisan('mod:mail', ['name' => 'Inventory:WidgetRestocked', '--markdown' => 'mail.widget-restocked']);
        expect($result->exitCode)->toBe(0)
            ->and(trim((string) preg_replace('/[ \t]+$/m', '', $result->normalisedOutput())))->toBe("INFO  Mailable [app/Modules/Inventory/Mail/WidgetRestocked.php] created successfully.\n\n   INFO  Markdown view [app/Modules/Inventory/resources/views/mail/widget-restocked.blade.php] created successfully.")
            ->and($w->read('app/Modules/Inventory/Mail/WidgetRestocked.php'))->toContain("markdown: 'inventory::mail.widget-restocked',")
            ->and($w->read('app/Modules/Inventory/resources/views/mail/widget-restocked.blade.php'))->toContain('<x-mail::message>');
    });
});
