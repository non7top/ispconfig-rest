<?php

/*
 * CI check (spec 010 US5): every column the test suite writes must exist in the
 * ISPConfig schema at ISPCONFIG_COMPAT's min_dbversion.
 *
 *   php scripts/check-compat-schema.php <written.json> <install/sql dir> [ISPCONFIG_COMPAT]
 *
 * <written.json> is produced by the test suite with COMPAT_COLLECT=<path>. The
 * sql directory is the `install/sql` folder of the ISPConfig release named by
 * tested_version: ispconfig3.sql (the current schema) and incremental/upd_NNNN.sql.
 * A column's required level is the number of the patch that adds it (0 when it is
 * in no patch); the step fails when that exceeds min_dbversion, or when a written
 * column exists in no schema. Statements it cannot classify are printed as
 * warnings, never skipped silently.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php check-compat-schema.php <written.json> <install/sql dir> [ISPCONFIG_COMPAT]\n");
    exit(2);
}

[$writtenPath, $sqlDir] = [$argv[1], rtrim($argv[2], '/')];
$compatPath = $argv[3] ?? dirname(__DIR__).'/ISPCONFIG_COMPAT';

$minDbversion = null;

foreach (@file($compatPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*min_dbversion\s*=\s*(\d+)\s*$/', $line, $m)) {
        $minDbversion = (int) $m[1];
    }
}

if ($minDbversion === null) {
    fwrite(STDERR, "ISPCONFIG_COMPAT has no valid min_dbversion: {$compatPath}\n");
    exit(2);
}

$written = json_decode((string) @file_get_contents($writtenPath), true);

if (! is_array($written)) {
    fwrite(STDERR, "cannot read the written-columns file: {$writtenPath}\n");
    exit(2);
}

/** Split on commas that are not inside parentheses or quotes. */
function splitTopLevel(string $sql): array
{
    $parts = [];
    $depth = 0;
    $quote = null;
    $buffer = '';

    foreach (str_split($sql) as $char) {
        if ($quote !== null) {
            $quote = $char === $quote ? null : $quote;
        } elseif ($char === "'" || $char === '"' || $char === '`') {
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

    return $parts;
}

/** @return list<string> statements without comments */
function statements(string $sql): array
{
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql);
    $sql = preg_replace('/^\s*(--|#).*$/m', '', $sql);

    return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $sql))));
}

function ident(string $name): string
{
    return strtolower(trim($name, "` \t\n\"'"));
}

/**
 * Columns declared by the lines of a CREATE TABLE body.
 *
 * @return list<string>
 */
function createColumns(string $body): array
{
    $columns = [];

    foreach (splitTopLevel($body) as $definition) {
        if ($definition === '' || preg_match('/^(primary|unique|key|index|fulltext|constraint|foreign|check)\b/i', $definition)) {
            continue;
        }

        if (preg_match('/^(`[^`]+`|\w+)\s/', $definition, $m)) {
            $columns[] = ident($m[1]);
        }
    }

    return $columns;
}

$current = [];

foreach (statements((string) @file_get_contents($sqlDir.'/ispconfig3.sql')) as $statement) {
    if (preg_match('/^create\s+table\s+(?:if\s+not\s+exists\s+)?(`[^`]+`|\w+)\s*\((.*)\)[^)]*$/is', $statement, $m)) {
        foreach (createColumns($m[2]) as $column) {
            $current[ident($m[1])][$column] = true;
        }
    }
}

if ($current === []) {
    fwrite(STDERR, "no tables found in {$sqlDir}/ispconfig3.sql\n");
    exit(2);
}

/** @var array<string, array<string, int>> $introduced table => column => patch number */
$introduced = [];
$patches = glob($sqlDir.'/incremental/upd_[0-9]*.sql') ?: [];
natsort($patches);

foreach ($patches as $file) {
    $number = (int) substr(basename($file), 4, -4);

    $source = (string) file_get_contents($file);

    if (preg_match('/^\s*DELIMITER\b/mi', $source)) {
        fwrite(STDERR, 'warning: upd_'.sprintf('%04d', $number).": procedural SQL (DELIMITER) is not analysed\n");

        continue;
    }

    foreach (statements($source) as $statement) {
        if (preg_match('/^create\s+table\s+(?:if\s+not\s+exists\s+)?(`[^`]+`|\w+)\s*\((.*)\)[^)]*$/is', $statement, $m)) {
            foreach (createColumns($m[2]) as $column) {
                $introduced[ident($m[1])][$column] ??= $number;
            }

            continue;
        }

        if (preg_match('/^alter\s+(?:ignore\s+)?table\s+(`[^`]+`|\w+)\s+(.*)$/is', $statement, $m)) {
            $table = ident($m[1]);

            foreach (splitTopLevel($m[2]) as $clause) {
                if (preg_match('/^add\s+(?:column\s+)?\((.*)\)$/is', $clause, $list)) {
                    foreach (createColumns($list[1]) as $column) {
                        $introduced[$table][$column] ??= $number;
                    }
                } elseif (preg_match('/^add\s+(?:column\s+)?(?:if\s+not\s+exists\s+)?(?!(?:primary|unique|key|index|fulltext|constraint|foreign)\b)(`[^`]+`|\w+)\s/i', $clause, $c)
                    || preg_match('/^change\s+(?:column\s+)?(?:if\s+exists\s+)?(?:`[^`]+`|\w+)\s+(`[^`]+`|\w+)\s/i', $clause, $c)) {
                    $introduced[$table][ident($c[1])] ??= $number;
                } elseif (! preg_match('/^(add\s+(primary|unique|key|index|fulltext|constraint|foreign)|modify|drop|rename|engine|convert|auto_increment|default|character|comment|alter|disable|enable|order|row_format)\b/i', $clause)) {
                    fwrite(STDERR, 'warning: upd_'.sprintf('%04d', $number).': unclassified ALTER clause: '.substr($clause, 0, 80)."\n");
                }
            }

            continue;
        }

        if (! preg_match('/^(insert|update|delete|drop|set|replace|truncate|select|call|use)\b/i', $statement)) {
            fwrite(STDERR, 'warning: upd_'.sprintf('%04d', $number).': unclassified statement: '.substr($statement, 0, 80)."\n");
        }
    }
}

$required = 0;
$failures = [];

foreach ($written as $table => $columns) {
    foreach ($columns as $column) {
        $key = ident($table);
        $column = ident($column);

        if (! isset($current[$key][$column]) && ! isset($introduced[$key][$column])) {
            $failures[] = "{$table}.{$column} exists in no ISPConfig schema (ispconfig3.sql or incremental patches)";

            continue;
        }

        $patch = $introduced[$key][$column] ?? 0;
        $required = max($required, $patch);

        if ($patch > $minDbversion) {
            $failures[] = sprintf('%s.%s needs schema level %d (upd_%04d.sql) but min_dbversion is %d', $table, $column, $patch, $patch, $minDbversion);
        }
    }
}

echo "required schema level: {$required}; min_dbversion: {$minDbversion}\n";

foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}

exit($failures === [] ? 0 : 1);
