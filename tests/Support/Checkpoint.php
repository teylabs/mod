<?php

namespace Tey\Mod\Tests\Support;

use Tey\Mod\Rename\Process;

/** Checkpoints must survive output drained by status checks and split reads. */
final class Checkpoint
{
    public static function wait(Process $process): bool
    {
        do {
            $process->checkTimeout();
            $running = $process->isRunning();
            // Symfony's Windows waitUntil() calls isRunning(), which can drain
            // the barrier before its separate callback sees it. Read the full
            // retained output, including bytes consumed by earlier status checks.
            if (str_contains($process->getOutput(), 'BARRIER')) {
                return true;
            }
            if (! $running) {
                return false;
            }
            usleep(1000);
        } while (true);
    }
}
