<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * `ispconfig:migrate` (spec 060 US2): idempotent, refuses below the minimum schema level.
 */
class MigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/migrate-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        ServerSchema::create();
        MonitorCompletionSchema::create();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 's1', 'dbversion' => 104]);
        Cache::flush();
        $this->enforceCompat();
        $this->dropApiTables();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    private function dropApiTables(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('migrations');
    }

    /** @return array{0: int, 1: string} */
    private function run_(string ...$options): array
    {
        $code = Artisan::call('ispconfig:migrate', array_fill_keys($options, true));

        return [$code, Artisan::output()];
    }

    public function test_pending_migrations_are_applied_and_the_empty_table_is_reported(): void
    {
        [$code, $out] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('applied 2026_07_04_000001_create_api_keys_table', $out);
        $this->assertStringContainsString('api_keys created empty', $out);
        $this->assertTrue(Schema::hasTable('api_keys'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->run_();
        DB::table('api_keys')->insert(['name' => 'k', 'key_hash' => str_repeat('a', 64)]);

        [$code, $out] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertSame("up to date\n", $out);
        $this->assertSame(1, DB::table('api_keys')->count());
    }

    public function test_check_reports_pending_with_exit_2_and_writes_nothing(): void
    {
        [$code, $out] = $this->run_('--check');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('1 pending: 2026_07_04_000001_create_api_keys_table', $out);
        $this->assertFalse(Schema::hasTable('api_keys'));
        $this->assertFalse(Schema::hasTable('migrations'));

        $this->run_();
        [$code, $out] = $this->run_('--check');

        $this->assertSame(0, $code);
        $this->assertSame("up to date\n", $out);
    }

    public function test_a_schema_level_below_the_minimum_refuses_before_any_ddl(): void
    {
        DB::table('server')->update(['dbversion' => 98]);

        foreach ([[], ['--check']] as $options) {
            [$code, $out] = $this->run_(...$options);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('level 98 is below the required 100', $out);
            $this->assertFalse(Schema::hasTable('api_keys'));
        }
    }

    public function test_a_failed_migration_stops_and_a_later_run_applies_only_the_rest(): void
    {
        file_put_contents($this->dir.'/2026_08_01_000001_create_t_a.php', $this->migration('Schema::create("t_a", fn ($t) => $t->id());'));
        file_put_contents($this->dir.'/fail', '');
        file_put_contents($this->dir.'/2026_08_01_000002_create_t_b.php', $this->migration('if (file_exists("'.$this->dir.'/fail")) { throw new RuntimeException("boom"); } Schema::create("t_b", fn ($t) => $t->id());'));
        $this->app->make('migrator')->path($this->dir);

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('applied 2026_07_04_000001_create_api_keys_table', $out);
        $this->assertStringContainsString('applied 2026_08_01_000001_create_t_a', $out);
        $this->assertStringContainsString('migration 2026_08_01_000002_create_t_b failed: boom', $out);

        unlink($this->dir.'/fail');

        [$code, $out] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertSame("applied 2026_08_01_000002_create_t_b\n", $out);
        $this->assertTrue(Schema::hasTable('t_b'));
    }

    public function test_a_login_failure_names_the_user_and_host_but_never_the_password(): void
    {
        config([
            'database.default' => 'unreachable',
            'database.connections.unreachable' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'dbispconfig',
                'username' => 'ispc_admin', 'password' => 'S3cr3t-pass',
            ],
        ]);
        DB::purge('unreachable');

        try {
            [$code, $out] = $this->run_();
        } finally {
            config(['database.default' => 'sqlite']);
        }

        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot log in to the database as ispc_admin@127.0.0.1', $out);
        $this->assertStringNotContainsString('S3cr3t-pass', $out);
    }

    private function migration(string $up): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        {$up}\n    }\n};\n";
    }
}
