<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F11 replaces bare known names with a warning and preserves escapes and unknown names', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $w->write('stubs/mod/@module/resources/js/components/badge.vue.stub', Frontend::source('F11', 'stubs/mod/@module/resources/js/components/badge.vue.stub'));
        $preview = json_decode($w->artisan('mod:badge', ['name' => 'Inventory:StockBadge', '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['would_write'])->toBeTrue()->and($preview['warnings'][0]['line'])->toBe(2);
        $result = $w->artisan('mod:badge', ['name' => 'Inventory:StockBadge'])->assertSuccessful();
        expect($result->normalisedOutput())->toEqualText(Frontend::output('F11'));
        expect($w->read('app/Modules/Inventory/resources/js/components/StockBadge.vue'))->toEqualText(Frontend::source('F11', 'app/Modules/Inventory/resources/js/components/StockBadge.vue'))
            ->and($result->normalisedOutput())->toContain('line 2: {{ name }} is a mod placeholder', '{{ name.studly }}', '@{{ name }}');
    });
});
