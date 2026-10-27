<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * `ispconfig:doctor` (spec 010 US3).
 */
class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/doctor-log-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config([
            'api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1'],
            'logging.channels.resilient.path' => $this->dir.'/app.log',
        ]);
        ServerSchema::create();
        MonitorCompletionSchema::create();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 's1', 'dbversion' => 104]);
        Cache::flush();
        $this->enforceCompat();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_healthy_prints_one_line_per_check_and_exits_zero(): void
    {
        $this->artisan('ispconfig:doctor')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('schema')
            ->expectsOutputToContain('migrations')
            ->expectsOutputToContain('logging')
            ->assertSuccessful();
    }

    public function test_a_failed_check_exits_one_and_says_why(): void
    {
        DB::table('server')->update(['dbversion' => 98]);

        $this->artisan('ispconfig:doctor')
            ->expectsOutputToContain('found dbversion 98, requires 100 (ISPConfig 3.3.1p1)')
            ->assertFailed();
    }

    public function test_an_unwritable_log_directory_is_degraded_but_exits_zero(): void
    {
        file_put_contents($this->dir.'/blocker', '');
        config(['logging.channels.resilient.path' => $this->dir.'/blocker/app.log']);

        $this->artisan('ispconfig:doctor')
            ->expectsOutputToContain('degraded')
            ->assertSuccessful();
    }
}
