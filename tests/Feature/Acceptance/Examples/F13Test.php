<?php

use Tey\Mod\Tests\Feature\Acceptance\Examples\Support\FrontendScenario as Frontend;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('F13 copies a Vue source byte for byte and reports name mentions without replacement', function () {
    Workspace::run(null, function (Workspace $w) {
        Frontend::setup($w);
        $path = 'app/Modules/Inventory/resources/js/pages/Widget/Index.vue';
        $source = Frontend::source('F3', $path)."\r\n";
        $w->write($path, $source);
        $args = ['--from' => $path, '--into' => '@module/resources/js/pages/list-page'];
        $preview = json_decode($w->artisan('mod:template', [...$args, '--dry-run' => true, '--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($preview['mentions'])->not->toBeEmpty()->and($w->exists('stubs/mod/@module/resources/js/pages/list-page.vue.stub'))->toBeFalse();
        $result = $w->artisan('mod:template', $args)->assertSuccessful();
        expect($result->normalisedOutput())->toBe(Frontend::output('F13'));
        expect($w->read('stubs/mod/@module/resources/js/pages/list-page.vue.stub'))->toBe($source)
            ->and($result->normalisedOutput())->toContain('(copied as it is)', 'Mentions', 'mod:list-page');
    });
});
