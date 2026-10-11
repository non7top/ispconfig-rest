<?php

namespace App\Console\Commands;

use App\Services\CompatibilityService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class Migrate extends Command
{
    protected $signature = 'ispconfig:migrate {--check : Report whether migrations are pending and write nothing}';

    protected $description = "Apply the API's own pending migrations; safe to run repeatedly (spec 060)";

    private const LOCK = 'ispconfig-rest:migrate';

    public function handle(CompatibilityService $compatibility, Migrator $migrator): int
    {
        if (($failure = $this->connectionFailure()) !== null) {
            return $this->refuse($failure);
        }

        $report = $compatibility->report();

        if (! $report->compatible()) {
            return $this->refuse($report->toException()->getMessage().' Nothing was changed.');
        }

        $paths = [database_path('migrations'), ...$migrator->paths()];

        if ($this->option('check')) {
            $pending = $this->pending($migrator, $paths);

            if ($pending === []) {
                $this->line('up to date');

                return self::SUCCESS;
            }

            $this->line(count($pending).' pending: '.implode(', ', $pending));

            return 2;
        }

        return $this->locked(fn () => $this->apply($migrator, $paths));
    }

    /** @param list<string> $paths */
    private function apply(Migrator $migrator, array $paths): int
    {
        if ($this->pending($migrator, $paths) === []) {
            $this->line('up to date');

            return self::SUCCESS;
        }

        $hadKeys = Schema::hasTable('api_keys');
        $before = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $failure = null;

        try {
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            $migrator->run($paths);
        } catch (Throwable $e) {
            $failure = $e;
        }

        // Read back from the repository so migrations applied before a failure are still reported.
        foreach (array_diff($migrator->getRepository()->getRan(), $before) as $name) {
            $this->line('applied '.$name);
        }

        if ($failure !== null) {
            return $this->refuse('migration '.($this->pending($migrator, $paths)[0] ?? 'unknown').' failed: '.$failure->getMessage());
        }

        if (! $hadKeys && Schema::hasTable('api_keys')) {
            $this->line('api_keys created empty: existing keys are gone, mint new ones with ispconfig-rest key:create');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function pending(Migrator $migrator, array $paths): array
    {
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff($files, $ran));
    }

    /** Never includes the password: the message is the user and host only. */
    private function connectionFailure(): ?string
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (Throwable) {
            $config = DB::connection()->getConfig();

            return sprintf('cannot log in to the database as %s@%s', $config['username'] ?? '?', $config['host'] ?? '?');
        }
    }

    /** MySQL named lock, so two runs end in the state of one; other drivers have none. */
    private function locked(Closure $run): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $run();
        }

        if ((int) $connection->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [self::LOCK])->acquired !== 1) {
            return $this->refuse('another migration is running');
        }

        try {
            return $run();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private function refuse(string $message): int
    {
        $this->getOutput()->getErrorStyle()->writeln($message);

        return self::FAILURE;
    }
}
