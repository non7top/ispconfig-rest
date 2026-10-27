<?php

namespace App\Support\Logging;

/**
 * Cheap, write-free state of the log destination for readiness, status and
 * doctor (spec 010 R5): `ok`, or `degraded` when the configured file cannot be
 * written or a fallback was used in this process.
 */
final class LogHealth
{
    private static bool $fellBack = false;

    public static function markFallback(): void
    {
        self::$fellBack = true;
    }

    public static function reset(): void
    {
        self::$fellBack = false;
    }

    public static function state(): string
    {
        $path = (string) config('logging.channels.resilient.path');
        $dir = dirname($path);

        if (self::$fellBack || ! is_dir($dir) || ! is_writable($dir)) {
            return 'degraded';
        }

        foreach (glob(dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'*.log') ?: [] as $existing) {
            if (! is_writable($existing)) {
                return 'degraded';
            }
        }

        return 'ok';
    }
}
