<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * A missing ISPConfig column or table becomes a typed problem naming only
 * identifiers (spec 010 US1). SQLite cannot raise 42S22/42S02, so the query
 * failures are synthetic.
 */
class CompatMissingColumnTest extends TestCase
{
    private const TYPE = 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#incompatible-ispconfig-schema';

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('api/v1/compat-probe/{case}', function (string $case): void {
            throw match ($case) {
                'column' => $this->failure('42S22', 1054, "Unknown column 'disable_symlinknotowner' in 'INSERT INTO'",
                    'insert into `web_domain` (`domain`, `disable_symlinknotowner`) values (?, ?)', ['secret-value.example', 'n']),
                'qualified' => $this->failure('42S22', 1054, "Unknown column 'web_domain.x' in 'field list'",
                    'select `web_domain`.`x` from `web_domain`', []),
                'unparseable' => $this->failure('42S22', 1054, "Unknown column 'x' in 'field list'", 'select 1', []),
                'table' => $this->failure('42S02', 1146, "Table 'dbispconfig.web_domain' doesn't exist",
                    'select * from `web_domain` where `id` = ?', ['secret-value.example']),
                'table-from-sql' => $this->failure('42S02', 1146, 'Base table or view not found',
                    'update "client" set "x" = ?', ['secret-value.example']),
                'deadlock' => $this->failure('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction',
                    'update `client` set `x` = ?', ['secret-value.example']),
                'integrity' => $this->failure('23000', 1062, "Duplicate entry 'secret-value.example' for key 'domain'",
                    'insert into `web_domain` (`domain`) values (?)', ['secret-value.example']),
            };
        });
    }

    /** @param  array<int, mixed>  $bindings */
    private function failure(string $state, int $code, string $message, string $sql, array $bindings): QueryException
    {
        $driver = new PDOException("SQLSTATE[{$state}]: {$message}", $code);
        $driver->errorInfo = [$state, $code, $message];

        return new QueryException('mysql', $sql, $bindings, $driver, ['host' => 'db.internal.example']);
    }

    public function test_missing_column_names_table_and_column(): void
    {
        $this->postJson('/api/v1/compat-probe/column')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => self::TYPE,
                'title' => 'Incompatible ISPConfig schema',
                'status' => 500,
                'detail' => "The ISPConfig database schema is older than this API expects: table web_domain is missing column disable_symlinknotowner. Run ISPConfig's updater.",
                'table' => 'web_domain',
                'column' => 'disable_symlinknotowner',
            ]);
    }

    public function test_qualified_column_names_table_and_column(): void
    {
        $this->postJson('/api/v1/compat-probe/qualified')
            ->assertStatus(500)
            ->assertJsonPath('type', self::TYPE)
            ->assertJsonPath('table', 'web_domain')
            ->assertJsonPath('column', 'x');
    }

    public function test_unparseable_statement_names_the_column_only(): void
    {
        $response = $this->postJson('/api/v1/compat-probe/unparseable')
            ->assertStatus(500)
            ->assertJsonPath('type', self::TYPE)
            ->assertJsonPath('column', 'x');

        $this->assertArrayNotHasKey('table', $response->json());
    }

    public function test_missing_table_names_the_table(): void
    {
        $response = $this->postJson('/api/v1/compat-probe/table')
            ->assertStatus(500)
            ->assertJsonPath('type', self::TYPE)
            ->assertJsonPath('table', 'web_domain');

        $this->assertArrayNotHasKey('column', $response->json());

        $this->postJson('/api/v1/compat-probe/table-from-sql')
            ->assertStatus(500)
            ->assertJsonPath('table', 'client');
    }

    public function test_body_never_contains_sql_bindings_or_connection_details(): void
    {
        foreach (['column', 'qualified', 'unparseable', 'table', 'table-from-sql'] as $case) {
            $body = $this->postJson("/api/v1/compat-probe/{$case}")->getContent();

            foreach (['insert into', 'select ', ' values', 'secret-value', 'db.internal', 'mysql', 'SQLSTATE', 'Connection:'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $body, "{$case} leaks {$needle}");
            }
        }
    }

    public function test_other_query_failures_stay_a_generic_problem(): void
    {
        config(['app.debug' => false]);

        foreach (['deadlock', 'integrity'] as $case) {
            $this->postJson("/api/v1/compat-probe/{$case}")
                ->assertStatus(500)
                ->assertExactJson(['type' => 'about:blank', 'title' => 'Internal server error', 'status' => 500]);
        }
    }

    public function test_the_full_exception_is_still_reported_to_the_log(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);

        $this->postJson('/api/v1/compat-probe/column')->assertStatus(500);

        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertInstanceOf(QueryException::class, $context['exception']);
        $this->assertStringContainsString('insert into `web_domain`', $message);
    }
}
