<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('A1 describes all twelve CRUD files and framework identities without writing', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::crud($w);
        $before = $w->files();
        $plan = json_decode($w->artisan('mod:crud-pages', ['name' => 'Inventory:Widget', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($plan['command'])->toBe('mod:crud-pages')->and($plan['group'])->toBe('Inventory')->and($plan['name'])->toBe('Widget')
            ->and($plan['files'])->toHaveCount(12)->and($plan['warnings'])->toBe([])->and($plan['would_write'])->toBeTrue()->and($w->files())->toBe($before);
        $page = array_values(array_filter($plan['files'], fn (array $file) => $file['alias'] === 'indexPage'))[0];
        expect($page['identity']['name'])->toBe('Inventory::Widget/Index')
            ->and($page['identity']['import'])->toBe('@modules/Inventory/resources/js/pages/Widget/Index.vue');
    });
});
