<?php

use PHPUnit\Framework\ExpectationFailedException;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

it('compares path separators on both sides of path expectations', function () {
    expect('app\\Models/Invoice.php')->toEqualPath('app/Models\\Invoice.php');
    expect('Created [app\\Models/Invoice.php]')->toContainPath('app/Models\\Invoice.php');
});

it('checks generated files and reports PHP lint failures clearly', function () {
    Workspace::run(null, function (Workspace $workspace) {
        $result = $workspace->artisan('mod:model', ['name' => 'ExpectationInvoice']);
        expect($result)->toHaveGenerated('app/Models/ExpectationInvoice.php', namespace: 'App\Models');
        expect(function () use ($result): void {
            expect($result)->toHaveGenerated('app/Models/ExpectationInvoice.php', namespace: 'Wrong');
        })
            ->toThrow(ExpectationFailedException::class, 'namespace Wrong;');
        $workspace->write('app/Invalid.php', '<?php class {');
        expect(function () use ($workspace): void {
            expect($workspace->root->path('app/Invalid.php'))->toBeValidPhp();
        })
            ->toThrow(ExpectationFailedException::class, 'is not valid PHP:');
    });
});

it('compares text newlines on both sides without losing spacing or final newlines', function () {
    expect("line one\r\n  line two\n")->toEqualText("line one\n  line two\r\n");
    expect(function (): void {
        expect("line\r\n")->toEqualText('line');
    })->toThrow(ExpectationFailedException::class);
    expect(function (): void {
        expect("  line\r\n")->toEqualText("line\n");
    })->toThrow(ExpectationFailedException::class);
});
