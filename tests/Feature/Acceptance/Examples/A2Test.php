<?php

use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Boost\Scenario;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('A2 pins the full Vue inventory with every previous section intact', function () {
    Workspace::run(null, function (Workspace $w) {
        Scenario::inventory($w);
        $before = Scenario::bytes($w);
        $output = $w->artisan('mod:list', ['--json' => true])->assertSuccessful()->normalisedOutput();
        $output = str_replace(Path::normalize(dirname(__DIR__, 4)), '<package>', $output);
        expect($output)->toEqualText(file_get_contents(__DIR__.'/../../../Fixtures/Boost/a2-vue.json'));
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['stack'])->toBe(['inertia' => 'vue', 'typescript' => true, 'pages' => 'resources/js/pages'])
            ->and($data['frontend']['view_namespace'])->toBe('{module.kebab}')
            ->and($data['frontend']['import_alias'])->toBe(['alias' => '@modules', 'root' => 'app/Modules'])
            ->and($data['views'][0]['namespace'])->toBe('inventory')
            ->and($data['routes'][0]['middleware_group'])->toBe('web')
            ->and($data['wiring'])->toBe(['inertia' => true, 'vite_alias' => true, 'tailwind' => true])
            ->and(JsonSchema::errors($data, (new InventorySectionRegistry)->schema()))->toBe([])
            ->and(Scenario::bytes($w))->toBe($before);
    });
});

it('A2 composes module-owned template and scaffold sources after L6', function () {
    // Enabled after rebasing onto L6; that lane owns module source reporting.
})->skip('Waiting for L6 to merge before pinning its module-source contract.');
