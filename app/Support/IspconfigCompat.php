<?php

namespace App\Support;

use RuntimeException;

/**
 * Parses the ISPCONFIG_COMPAT file: the ISPConfig schema level and release
 * this API supports (spec 010).
 */
final class IspconfigCompat
{
    private const REQUIRED = ['min_dbversion', 'min_version', 'tested_version'];

    private const OPTIONAL = ['schema_tarball_sha256'];

    /**
     * @return array{min_dbversion: int, min_version: string, tested_version: string, schema_tarball_sha256?: string}
     */
    public static function load(?string $path = null): array
    {
        $path ??= base_path('ISPCONFIG_COMPAT');

        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

        if ($lines === false) {
            throw new RuntimeException("ISPCONFIG_COMPAT is missing or unreadable: {$path}");
        }

        $values = [];

        foreach ($lines as $number => $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $key = trim($key);

            if ($value === null || ! in_array($key, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                throw new RuntimeException('ISPCONFIG_COMPAT line '.($number + 1).": unknown or malformed entry '{$key}'");
            }

            $values[$key] = trim($value);
        }

        foreach (self::REQUIRED as $key) {
            if (($values[$key] ?? '') === '') {
                throw new RuntimeException("ISPCONFIG_COMPAT is missing the required key {$key}");
            }
        }

        if (! ctype_digit($values['min_dbversion']) || (int) $values['min_dbversion'] < 1) {
            throw new RuntimeException('ISPCONFIG_COMPAT min_dbversion must be an integer of at least 1');
        }

        if (isset($values['schema_tarball_sha256']) && ! preg_match('/^[0-9a-f]{64}$/', $values['schema_tarball_sha256'])) {
            throw new RuntimeException('ISPCONFIG_COMPAT schema_tarball_sha256 must be 64 hexadecimal characters');
        }

        $values['min_dbversion'] = (int) $values['min_dbversion'];

        return $values;
    }
}
