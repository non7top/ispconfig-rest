<?php

namespace Tests\Support;

/**
 * Collects the (table, column) pairs the test suite writes, for the CI schema
 * check (spec 010 US5, scripts/check-compat-schema.php). Enabled by setting
 * COMPAT_COLLECT to an output path.
 */
final class WrittenColumns
{
    /** Framework and API-owned tables: not part of the ISPConfig schema. */
    private const IGNORED = '/^(api_.*|migrations|cache|cache_locks|sessions|jobs|job_batches|failed_jobs|password_reset_tokens|users|sqlite_.*)$/';

    /** @var array<string, array<string, true>> */
    private static array $written = [];

    private static bool $registered = false;

    public static function register(string $path): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        register_shutdown_function(function () use ($path): void {
            ksort(self::$written);
            $out = array_map(fn (array $columns) => array_keys($columns), self::$written);
            file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });
    }

    public static function record(string $sql): void
    {
        foreach (self::parse($sql) as [$table, $column]) {
            if (! preg_match(self::IGNORED, $table)) {
                self::$written[$table][$column] = true;
            }
        }
    }

    /** @return list<array{string, string}> */
    public static function parse(string $sql): array
    {
        $pairs = [];

        if (preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?|replace)\s+(?:ignore\s+)?into\s+[`"]?(\w+)[`"]?\s*\((.*?)\)\s*(?:values|select)\b/is', $sql, $m)) {
            foreach (self::split($m[2]) as $column) {
                $pairs[] = [strtolower($m[1]), self::name($column)];
            }
        } elseif (preg_match('/^\s*update\s+(?:or\s+\w+\s+)?[`"]?(\w+)[`"]?\s+set\s+(.*?)(?:\s+where\s+.*)?$/is', $sql, $m)) {
            foreach (self::split($m[2]) as $assignment) {
                if (preg_match('/^(?:[`"]?\w+[`"]?\.)?[`"]?(\w+)[`"]?\s*=/', $assignment, $c)) {
                    $pairs[] = [strtolower($m[1]), strtolower($c[1])];
                }
            }
        }

        return $pairs;
    }

    /** @return list<string> */
    private static function split(string $list): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $buffer = '';

        foreach (str_split($list) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = trim($buffer);

        return array_values(array_filter($parts, fn (string $part) => $part !== ''));
    }

    private static function name(string $column): string
    {
        return strtolower(trim($column, '`" '));
    }
}
