<?php

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tey\Mod\Tests\Support\BoundedProcess;

it('fails a missing checkpoint within its hard timeout and terminates the worker', function (string $wait) {
    $process = new BoundedProcess([PHP_BINARY, '-r', '$server = stream_socket_server("tcp://127.0.0.1:0"); stream_socket_accept($server, 10);'], timeout: 0.25);
    $started = microtime(true);
    try {
        $process->start();
        $await = $wait === 'checkpoint'
            ? fn () => $process->waitUntil(static fn (): bool => false)
            : fn () => $process->wait();
        expect($await)->toThrow(ProcessTimedOutException::class);
        expect($process->isRunning())->toBeFalse()->and(microtime(true) - $started)->toBeLessThan(8);
    } finally {
        $process->stop(0, 9);
    }
})->with(['checkpoint', 'completion']);
