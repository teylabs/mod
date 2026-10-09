<?php

use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('preserves the complete 0.2 inventory bytes for every built-in layout', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        $output = $w->artisan('mod:list', ['--json' => true])->assertSuccessful()->normalisedOutput();
        expect($output)->toBe(str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../../Fixtures/list/'.$layout.'.json')));
    });
})->with(['laravel', 'modules', 'ddd', 'features', 'slices', 'type-first']);
