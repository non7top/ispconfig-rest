<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use App\Support\CheckResult;
use Illuminate\Console\Command;

class Doctor extends Command
{
    protected $signature = 'ispconfig:doctor';

    protected $description = 'Check the database, the ISPConfig schema level, the API migrations and the log directory';

    public function handle(HealthCheckService $health): int
    {
        $results = array_map(fn (CheckResult $check) => $check->name === 'logging' ? $this->withWriteProbe($check) : $check, $health->run());

        foreach ($results as $check) {
            $this->line(sprintf('%-11s %-8s %s', $check->name, $check->state, $check->detail ?? ''));
        }

        return $health->ready($results) ? self::SUCCESS : self::FAILURE;
    }

    /** Readiness only inspects permissions; doctor really writes, as the run user. */
    private function withWriteProbe(CheckResult $check): CheckResult
    {
        $dir = dirname((string) config('logging.channels.resilient.path'));
        $probe = $dir.'/.doctor-'.getmypid();

        if (@file_put_contents($probe, '') === false) {
            return new CheckResult('logging', CheckResult::DEGRADED, "{$dir} is not writable");
        }

        @unlink($probe);

        return $check;
    }
}
