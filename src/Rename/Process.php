<?php

namespace Tey\Mod\Rename;

use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

/** @internal Rename subprocesses with deadlines for Windows waits and process-tree termination. */
final class Process extends SymfonyProcess
{
    /** @phpstan-impure Reads live child status, which can change without a PHP method call. */
    public function isRunning(): bool
    {
        return parent::isRunning();
    }

    public function wait(?callable $callback = null): int
    {
        if (PHP_OS_FAMILY === 'Windows' && $callback === null) {
            // isRunning() pumps stdin and drains both output streams without
            // WindowsPipes' 200ms blocking poll. start() already installed any
            // run() callback. Keep timeout checks and Symfony's final exit handling.
            while ($this->isRunning()) {
                $this->checkTimeout();
                usleep(1000);
            }
        }

        return parent::wait($callback);
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return parent::stop(min($timeout, 5), $signal);
        }
        if (! $this->isRunning()) {
            return $this->getExitCode();
        }
        $pid = $this->getPid();
        if ($pid === null) {
            throw new RuntimeException('Running rename subprocess has no owned process id.');
        }
        // Symfony's Windows stop() uses an unbounded exec(taskkill). Launch
        // the native tree killer directly, without a shell, and bound it too.
        $killer = proc_open([(getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\taskkill.exe', '/F', '/T', '/PID', (string) $pid], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($killer)) {
            throw new RuntimeException('Cannot start Windows termination for rename subprocess '.$pid);
        }
        $deadline = microtime(true) + 5;
        try {
            do {
                $status = proc_get_status($killer);
                if (! $status['running']) {
                    proc_close($killer);
                    $killer = null;
                    break;
                }
                usleep(10000); // Poll actual exit status; never assume completion after a sleep.
            } while (microtime(true) < $deadline);
            if (is_resource($killer)) {
                throw new RuntimeException('Windows taskkill exceeded its 5-second hard timeout for rename subprocess '.$pid);
            }
            while ($this->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if ($this->isRunning()) {
                throw new RuntimeException('Windows rename subprocess '.$pid.' did not terminate within 5 seconds.');
            }

            return $this->getExitCode();
        } finally {
            if (is_resource($killer)) {
                proc_terminate($killer);
                // Do not call blocking proc_close() on a still-running killer.
                if (! proc_get_status($killer)['running']) {
                    proc_close($killer);
                }
            }
        }
    }
}
