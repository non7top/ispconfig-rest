<?php

namespace App\Services;

use App\Support\CompatReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compares the ISPConfig schema level (server.dbversion, the updater's own
 * counter) with ISPCONFIG_COMPAT (spec 010 R1). Read-only; single-server
 * installs only. MIN() makes a second server row err on the side of refusing.
 */
class CompatibilityService
{
    private const CACHE_KEY = 'ispconfig-rest:compat';

    public function __construct(private readonly MonitorDataService $monitor) {}

    /** Only a compatible level is cached, so recovery after the updater is seen on the next call. */
    public function report(): CompatReport
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            $report = $this->evaluate($cached['dbversion'], $cached['version']);

            if ($report->compatible()) {
                return $report;
            }
        }

        try {
            $level = DB::table('server')->min('dbversion');
        } catch (Throwable) {
            return $this->build(CompatReport::UNREACHABLE);
        }

        if ($level === null) {
            return $this->build(CompatReport::UNKNOWN);
        }

        $report = $this->evaluate((int) $level, $this->monitor->ispconfigVersion());

        if ($report->compatible()) {
            try {
                Cache::put(self::CACHE_KEY, ['dbversion' => $report->dbversion, 'version' => $report->version], (int) config('api.compat_cache_ttl'));
            } catch (Throwable) {
                // The cache only saves a query; a broken one must not fail requests.
            }
        }

        return $report;
    }

    private function evaluate(int $dbversion, ?string $version): CompatReport
    {
        $required = (int) config('api.compat.min_dbversion');

        return $this->build($dbversion >= $required ? CompatReport::OK : CompatReport::INCOMPATIBLE, $dbversion, $version);
    }

    private function build(string $state, ?int $dbversion = null, ?string $version = null): CompatReport
    {
        return new CompatReport(
            $state,
            (int) config('api.compat.min_dbversion'),
            (string) config('api.compat.min_version'),
            $dbversion,
            $version,
        );
    }
}
