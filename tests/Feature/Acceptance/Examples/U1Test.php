<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('U1 places HTTP classes under Http without upgrade warnings or moving existing files', function () {
    Workspace::run(null, function (Workspace $w) {
        putenv('COLUMNS=72');
        config()->set('mod.layout', 'modules');
        $legacy = "<?php\n// existing controller\n";
        $w->write('app/Modules/Knowledge/Controllers/ExistingController.php', $legacy);
        $result = $w->artisan('mod:controller', ['name' => 'Knowledge:DocumentExportController']);
        $result->assertSuccessful();
        expect(trim($result->normalisedOutput()))->toBe('INFO  Controller [app/Modules/Knowledge/Http/Controllers/DocumentExportController.php] created successfully.')
            ->and(str_replace("\r\n", "\n", $w->read('app/Modules/Knowledge/Http/Controllers/DocumentExportController.php')))->toContain('namespace App\\Modules\\Knowledge\\Http\\Controllers;')
            ->and($w->read('app/Modules/Knowledge/Controllers/ExistingController.php'))->toBe($legacy);
    });
});
