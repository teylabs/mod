<?php

use Symfony\Component\Process\Exception\ProcessFailedException;
use Tey\Mod\Rename\Process;

it('drains both output streams while sending input and preserves the child exit status', function () {
    $input = str_repeat("input\0", 180000);
    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("o", 1048576)); fwrite(STDERR, str_repeat("e", 1048576)); $input = stream_get_contents(STDIN); fwrite(STDOUT, hash("sha256", $input)); exit(37);'], input: $input, timeout: 15);
    $seen = ['out' => '', 'err' => ''];
    try {
        $exit = $process->run(function (string $type, string $bytes) use (&$seen): void {
            $seen[$type] .= $bytes;
        });
        expect($exit)->toBe(37)
            ->and($process->getOutput())->toBe(str_repeat('o', 1048576).hash('sha256', $input))
            ->and($process->getErrorOutput())->toBe(str_repeat('e', 1048576))
            ->and($seen)->toBe(['out' => $process->getOutput(), 'err' => $process->getErrorOutput()])
            ->and($process->isRunning())->toBeFalse();
    } finally {
        $process->stop(0, 9);
    }
});

it('retains mustRun failure reporting for a child that exits unsuccessfully', function () {
    $process = new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "child failure"); exit(37);'], timeout: 15);
    expect(fn () => $process->mustRun())->toThrow(ProcessFailedException::class)
        ->and($process->getExitCode())->toBe(37)
        ->and($process->getErrorOutput())->toBe('child failure')
        ->and($process->isRunning())->toBeFalse();
});
