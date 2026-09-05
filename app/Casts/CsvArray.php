<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Casts a comma-separated ISPConfig column (e.g. remote_user.remote_ips,
 * remote_user.remote_functions) to a JSON array and back, the same
 * csv_array presentation SystemConfigService already uses for
 * sys_ini.web_php_options - promoted to a reusable cast here because
 * RemoteUser has two such columns and gains nothing from re-deriving the
 * same explode/implode by hand.
 *
 * Empty string <-> empty array. Entries are trimmed; blank entries
 * (a stray leading/trailing/double comma) are dropped on read so the API
 * never surfaces "" as a value, but nothing here validates entry CONTENT
 * (that a remote_ips entry is a real IP, that a remote_functions entry is
 * a real function name) - that belongs to the request's validation rules,
 * which see the already-decoded array.
 */
class CsvArray implements CastsAttributes
{
    /**
     * @return array<int, string>
     */
    public function get($model, string $key, $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            fn (string $entry): bool => $entry !== ''
        ));
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        if ($value === null) {
            return '';
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return implode(',', array_values(array_filter(
            array_map('trim', array_map('strval', $items)),
            fn (string $entry): bool => $entry !== ''
        )));
    }
}
