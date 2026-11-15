<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use App\Support\CheckResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Doctor extends Command
{
    protected $signature = 'ispconfig:doctor {--log : Also write a warning through the application log and confirm it reached today\'s file}';

    protected $description = 'Check the database, the ISPConfig schema level, the API migrations and the log directory';

    public function handle(HealthCheckService $health): int
    {
        $results = array_map(fn (CheckResult $check) => $check->name === 'logging' ? $this->withWriteProbe($check) : $check, $health->run());

        if ($this->option('log')) {
            $results[] = $this->logWrite();
        }

        foreach ($results as $check) {
            $this->line(sprintf('%-11s %-8s %s', $check->name, $check->state, $check->detail ?? ''));
        }

        return $health->ready($results) ? self::SUCCESS : self::FAILURE;
    }

    /** Proves a real entry reaches the log file, through the same channel the application uses. */
    private function logWrite(): CheckResult
    {
        $marker = 'ispconfig:doctor log write test '.bin2hex(random_bytes(4));
        Log::warning($marker);

        $path = (string) config('logging.channels.resilient.path');
        $file = dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-'.date('Y-m-d').'.'.pathinfo($path, PATHINFO_EXTENSION);

        return is_file($file) && str_contains((string) @file_get_contents($file), $marker)
            ? new CheckResult('log write', CheckResult::OK, $file)
            : new CheckResult('log write', CheckResult::FAILED, "the entry did not reach {$file}; check LOG_LEVEL, LOG_CHANNEL and the directory's owner");
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
