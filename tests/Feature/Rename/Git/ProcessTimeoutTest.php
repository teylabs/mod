<?php

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Tey\Mod\Rename\Process;
use Tey\Mod\Tests\Support\Checkpoint;

it('fails a missing checkpoint within its hard timeout and terminates the worker', function (string $wait) {
    $process = new Process([PHP_BINARY, '-r', '$server = stream_socket_server("tcp://127.0.0.1:0"); stream_socket_accept($server, 10);'], timeout: 0.25);
    $started = microtime(true);
    try {
        $process->start();
        $await = $wait === 'checkpoint'
            ? fn () => Checkpoint::wait($process)
            : fn () => $process->wait();
        expect($await)->toThrow(ProcessTimedOutException::class);
        expect($process->isRunning())->toBeFalse()->and(microtime(true) - $started)->toBeLessThan(8);
    } finally {
        $process->stop(0, 9);
    }
})->with(['checkpoint', 'completion']);

it('recognizes a checkpoint already drained by a process status check', function () {
    $process = new Process([PHP_BINARY, '-r', '$server = stream_socket_server("tcp://127.0.0.1:0"); fwrite(STDOUT, "BARRIER ready\n"); fflush(STDOUT); stream_socket_accept($server, 10);'], timeout: 5);
    try {
        $process->start();
        while (! str_contains($process->getOutput(), 'BARRIER')) {
            $process->checkTimeout();
            expect($process->isRunning())->toBeTrue();
            usleep(1000);
        }
        expect(Checkpoint::wait($process))->toBeTrue();
    } finally {
        $process->stop(0, 9);
    }
});

it('recognizes a checkpoint split across separate drained reads', function () {
    $input = new InputStream;
    $process = new Process([PHP_BINARY, '-r', '$server = stream_socket_server("tcp://127.0.0.1:0"); fwrite(STDOUT, "BARR"); fflush(STDOUT); fgets(STDIN); fwrite(STDOUT, "IER ready\n"); fflush(STDOUT); stream_socket_accept($server, 10);'], input: $input, timeout: 5);
    try {
        $process->start();
        while ($process->getOutput() !== 'BARR') {
            $process->checkTimeout();
            expect($process->isRunning())->toBeTrue();
            usleep(1000);
        }
        $input->write("continue\n");
        expect(Checkpoint::wait($process))->toBeTrue()
            ->and($process->getOutput())->toContain('BARRIER ready');
    } finally {
        $input->close();
        $process->stop(0, 9);
    }
});
