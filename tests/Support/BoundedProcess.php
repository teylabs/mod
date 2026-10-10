<?php

namespace Tey\Mod\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Test subprocesses have finite waits, including Windows process-tree termination. */
final class BoundedProcess extends Process
{
    /** @phpstan-impure Reads live child status, which can change without a PHP method call. */
    public function isRunning(): bool
    {
        return parent::isRunning();
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
            throw new RuntimeException('Running test subprocess has no owned process id.');
        }
        // Symfony's Windows stop() uses an unbounded exec(taskkill). Launch
        // the native tree killer directly, without a shell, and bound it too.
        $killer = proc_open([(getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\taskkill.exe', '/F', '/T', '/PID', (string) $pid], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($killer)) {
            throw new RuntimeException('Cannot start Windows termination for test subprocess '.$pid);
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
                throw new RuntimeException('Windows taskkill exceeded its 5-second hard timeout for test subprocess '.$pid);
            }
            while ($this->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if ($this->isRunning()) {
                throw new RuntimeException('Windows test subprocess '.$pid.' did not terminate within 5 seconds.');
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
