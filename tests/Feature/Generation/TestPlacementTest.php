<?php

use Tey\Mod\Tests\Feature\Generation\Support\NativeLayout;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('places native test stubs under the test root and retains placement with --unit', function (bool $unit, bool $pest, string $placement, string $name, string $relative) {
    Workspace::run(null, function (Workspace $workspace) use ($unit, $pest, $placement, $name, $relative) {
        NativeLayout::extend();
        $workspace->artisan('mod:test', [
            'name' => $name,
            '--in' => $placement,
            '--unit' => $unit,
            $pest ? '--pest' : '--phpunit' => true,
        ])->assertSuccessful();

        expect($workspace->files())->toBe(['tests/'.$relative.'.php']);
        $bytes = $workspace->read('tests/'.$relative.'.php');

        if ($pest) {
            expect($bytes)->toContain("test('example'");
        } else {
            $namespace = 'Tests\\'.str_replace('/', '\\', dirname($relative));
            expect($bytes)->toContain('namespace '.$namespace.';')
                ->toContain('class '.basename($relative));
        }
    });
})->with([
    [false, false, '', 'ExampleTest', 'Feature/ExampleTest'],
    [true, false, '', 'ExampleTest', 'Unit/ExampleTest'],
    [false, false, 'Billing', 'ExampleTest', 'Feature/Billing/ExampleTest'],
    [true, false, 'Billing', 'ExampleTest', 'Unit/Billing/ExampleTest'],
    [false, true, 'Billing', 'ExampleTest', 'Feature/Billing/ExampleTest'],
    [true, true, 'Billing', 'ExampleTest', 'Unit/Billing/ExampleTest'],
    [true, false, 'Billing', 'Nested/ExampleTest', 'Unit/Billing/Nested/ExampleTest'],
    [false, false, '', 'Billing:ExampleTest', 'Feature/Billing/ExampleTest'],
    [true, false, '', 'Billing:ExampleTest', 'Unit/Billing/ExampleTest'],
]);
