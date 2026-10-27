<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

class Doctor extends Command
{
    protected $signature = 'ispconfig:doctor';

    protected $description = 'Check the database, the ISPConfig schema level and the API migrations';

    public function handle(HealthCheckService $health): int
    {
        $results = $health->run();

        foreach ($results as $check) {
            $this->line(sprintf('%-11s %-8s %s', $check->name, $check->state, $check->detail ?? ''));
        }

        return $health->ready($results) ? self::SUCCESS : self::FAILURE;
    }
}
