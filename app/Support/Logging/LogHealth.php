<?php

namespace App\Support\Logging;

/**
 * Cheap, write-free state of the log destination for readiness, status and
 * doctor (spec 010 R5): `ok`, or `degraded` when the log directory or today's
 * file cannot be written, or a fallback was used in this process. Files of
 * earlier days are not judged: a rotated file left by another user (an old
 * install's root-run command) never receives another write.
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
        return self::problem() === null ? 'ok' : 'degraded';
    }

    /** What is wrong with the log destination, or null when nothing is. */
    public static function problem(): ?string
    {
        $path = (string) config('logging.channels.resilient.path');
        $dir = dirname($path);

        if (! is_dir($dir) || ! is_writable($dir)) {
            return $dir.' is not writable';
        }

        // RotatingFileHandler writes <name>-<date>.<ext> in the configured directory.
        $today = $dir.'/'.pathinfo($path, PATHINFO_FILENAME).'-'.date('Y-m-d').'.'.pathinfo($path, PATHINFO_EXTENSION);

        if (file_exists($today) && ! is_writable($today)) {
            return $today.' is not writable';
        }

        return self::$fellBack ? 'the log file could not be written; the PHP error log was used' : null;
    }
}
