<?php

use Tey\Mod\Facades\Mod;
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

it('U1 restores the four 0.2 folders through generates overrides', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        Mod::layout('modules')
            ->generates('controller', in: '@module/Controllers', suffix: 'Controller')
            ->generates('request', in: '@module/Requests', suffix: 'Request')
            ->generates('middleware', in: '@module/Middleware')
            ->generates('resource', in: '@module/Resources')
            ->frontend(pages: 'app/Modules/{module}/ui/js/pages', components: 'app/Modules/{module}/ui/js/components', css: 'app/Modules/{module}/ui/css', views: 'app/Modules/{module}/ui/views');
        foreach (['controller' => 'DocumentExportController', 'request' => 'StoreDocumentRequest', 'middleware' => 'CheckDocument', 'resource' => 'DocumentResource'] as $type => $name) {
            $w->artisan('mod:'.$type, ['name' => 'Knowledge:'.$name])->assertSuccessful();
        }
        foreach (['Controllers/DocumentExportController', 'Requests/StoreDocumentRequest', 'Middleware/CheckDocument', 'Resources/DocumentResource'] as $file) {
            expect($w->exists('app/Modules/Knowledge/'.$file.'.php'))->toBeTrue();
        }
    });
});
