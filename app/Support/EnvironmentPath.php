<?php

namespace App\Support;

/**
 * Where the environment file lives (spec 060): /etc/ispconfig-rest when it has
 * one, so a packaged, read-only tree needs no file of its own; otherwise the
 * install directory, as installs from before the package keep it there.
 */
final class EnvironmentPath
{
    public const CONFIG_DIR = '/etc/ispconfig-rest';

    public static function resolve(string $basePath, string $configDir = self::CONFIG_DIR): string
    {
        return is_file($configDir.'/.env') ? $configDir : $basePath;
    }
}
