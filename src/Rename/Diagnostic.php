<?php

namespace Tey\Mod\Rename;

/** Temporary opt-in hosted Windows timing instrumentation. */
final class Diagnostic
{
    public static function start(string $label): float
    {
        $started = microtime(true);
        self::record('start', $label, $started);

        return $started;
    }

    public static function finish(string $label, float $started): void
    {
        self::record('finish', $label, microtime(true), microtime(true) - $started);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function measure(string $label, callable $action): mixed
    {
        $started = self::start($label);
        try {
            return $action();
        } finally {
            self::finish($label, $started);
        }
    }

    private static function record(string $event, string $label, float $time, ?float $elapsed = null): void
    {
        $path = getenv('MOD_RENAME_DIAGNOSTIC_LOG');
        if (PHP_OS_FAMILY === 'Windows' && is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['pid' => getmypid(), 'event' => $event, 'label' => $label, 'time' => $time, 'elapsed' => $elapsed])."\n", FILE_APPEND);
        }
    }
}
