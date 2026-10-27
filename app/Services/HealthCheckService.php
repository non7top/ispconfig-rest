<?php

namespace App\Services;

use App\Support\CheckResult;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The checks behind /readyz, /api/v1/status and `ispconfig-rest doctor`
 * (spec 010), so the three cannot disagree.
 */
class HealthCheckService
{
    public function __construct(
        private readonly CompatibilityService $compatibility,
        private readonly Migrator $migrator,
    ) {}

    /** @return list<CheckResult> */
    public function run(): array
    {
        return [$this->database(), $this->schema(), $this->migrations()];
    }

    /** Ready unless a check failed. */
    public function ready(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->state === CheckResult::FAILED) {
                return false;
            }
        }

        return true;
    }

    private function database(): CheckResult
    {
        try {
            DB::select('select 1');

            return new CheckResult('database', CheckResult::OK);
        } catch (Throwable) {
            return new CheckResult('database', CheckResult::FAILED, 'the database is not reachable');
        }
    }

    /** An unreadable level counts as failed: writes are refused too. */
    private function schema(): CheckResult
    {
        $report = $this->compatibility->report();

        if ($report->compatible()) {
            return new CheckResult('schema', CheckResult::OK);
        }

        $detail = $report->state === 'incompatible'
            ? "found dbversion {$report->dbversion}, requires {$report->requiredDbversion} (ISPConfig {$report->minVersion})"
            : "the schema level could not be determined (requires {$report->requiredDbversion})";

        return new CheckResult('schema', CheckResult::FAILED, $detail);
    }

    private function migrations(): CheckResult
    {
        try {
            $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]));
            $pending = array_diff($files, $this->migrator->getRepository()->getRan());
        } catch (Throwable) {
            return new CheckResult('migrations', CheckResult::FAILED, 'the migrations table cannot be read');
        }

        return $pending === []
            ? new CheckResult('migrations', CheckResult::OK)
            : new CheckResult('migrations', CheckResult::FAILED, count($pending).' pending: '.implode(', ', $pending));
    }
}
